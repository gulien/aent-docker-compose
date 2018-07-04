<?php

namespace TheAentMachine\AentDockerCompose\DockerCompose;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use TheAentMachine\AentDockerCompose\YamlTools\YamlTools;
use TheAentMachine\Pheromone;
use TheAentMachine\Service\Enum\VolumeTypeEnum;
use TheAentMachine\Service\Environment\EnvVariable;
use TheAentMachine\Service\Service;
use TheAentMachine\Service\Volume\BindVolume;
use TheAentMachine\Service\Volume\NamedVolume;
use TheAentMachine\Service\Volume\TmpfsVolume;
use TheAentMachine\Service\Volume\Volume;

class DockerComposeService
{
    public const VERSION = '3.3';

    /** @var LoggerInterface */
    private $log;

    /** @var DockerComposeFile[] */
    private $files;

    public function __construct(LoggerInterface $log)
    {
        $this->log = $log;
    }

    private function seekFiles(): void
    {
        $containerProjectDir = Pheromone::getContainerProjectDirectory();

        $finder = new Finder();
        $dockerComposeFileFilter = function (\SplFileInfo $file) {
            return $file->isFile() && preg_match('/^docker-compose(.)*\.(yaml|yml)$/', $file->getFilename());
        };
        $finder->files()->filter($dockerComposeFileFilter)->in($containerProjectDir)->depth('== 0');

        if (!$finder->hasResults()) {
            $this->log->info("no docker-compose file found, let's create it");
            $this->createDockerComposeFile($containerProjectDir . '/docker-compose.yml');
            return;
        }

        /** @var \SplFileInfo $file */
        foreach ($finder as $file) {
            $this->files[] = new DockerComposeFile($file);
            $this->log->info($file->getFilename() . ' has been found');
        }
    }

    /**
     * @return string[]
     */
    public function getDockerComposePathnames(): array
    {
        if ($this->files === null) {
            $this->seekFiles();
        }
        $pathnames = array();
        foreach ($this->files as $file) {
            $pathnames[] = $file->getPathname();
        }
        return $pathnames;
    }

    public function filesInitialized(): bool
    {
        return !(null === $this->files || empty($this->files));
    }

    private function createDockerComposeFile(string $path): void
    {
        // TODO ask questions about version and so on!
        $fileSystem = new Filesystem();
        $fileSystem->dumpFile($path, "version: '" . self::VERSION . "'");

        $dirInfo = new \SplFileInfo(\dirname($path));
        chown($path, $dirInfo->getOwner());
        chgrp($path, $dirInfo->getGroup());

        $file = new DockerComposeFile(new \SplFileInfo($path));
        $this->files[] = $file;
        $this->log->info($file->getFilename() . ' was successfully created!');
    }

    /**
     * @param Service $service
     * @param string $version
     * @return mixed[]
     */
    public static function dockerComposeServiceSerialize(Service $service, string $version = self::VERSION): array
    {
        $portMap = function (array $port): string {
            return $port['source'] . ':' . $port['target'];
        };
        $labelMap = function (array $label): string {
            return $label['value'];
        };
        $envMap = function (EnvVariable $e): string {
            return $e->getValue();
        };
        /**
         * @param NamedVolume|BindVolume|TmpfsVolume $v
         * @return array
         */
        $volumeMap = function ($v): array {
            $array = [
                'type' => $v->getType(),
                'source' => $v->getSource(),
            ];
            if ($v instanceof NamedVolume || $v instanceof BindVolume) {
                $array['target'] = $v->getTarget();
                $array['read_only'] = $v->isReadOnly();
            }
            return $array;
        };
        $dockerService = [
            'version' => $version,
            'services' => [
                $service->getServiceName() => array_filter([
                    'image' => $service->getImage(),
                    'command' => $service->getCommand(),
                    'depends_on' => $service->getDependsOn(),
                    'ports' => array_map($portMap, $service->getPorts()),
                    'labels' => array_map($labelMap, $service->getLabels()),
                    'environment' => array_map($envMap, $service->getEnvironment()),
                    'volumes' => array_map($volumeMap, $service->getVolumes()),
                ]),
            ],
        ];
        $namedVolumes = array();
        /** @var Volume $volume */
        foreach ($service->getVolumes() as $volume) {
            if ($volume->getType() === VolumeTypeEnum::NAMED_VOLUME) {
                // for now we just add them without any option
                $namedVolumes[$volume->getSource()] = null;
            }
        }
        if (!empty($namedVolumes)) {
            $dockerService['volumes'] = $namedVolumes;
        }
        return $dockerService;
    }

    /**
     * @param string $pathname
     */
    public static function checkDockerComposeFileValidity(string $pathname): void
    {
        $command = ['docker-compose', '-f', $pathname, 'config', '-q'];
        $process = new Process($command);
        $process->enableOutput();
        $process->setTty(true);
        $process->mustRun();
    }


    /**
     * Merge some yaml content into a docker-compose file (and check its validity, by default)
     * @param mixed[]|string $content
     * @param string $file
     * @param bool $checkValidity
     */
    public static function mergeContentInDockerComposeFile($content, string $file, bool $checkValidity = true): void
    {
        self::mergeContentInDockerComposeFiles($content, [$file], $checkValidity);
    }

    /**
     * Merge some yaml content into multiple docker-compose files (and check their validity, by default)
     * @param mixed[]|string $content
     * @param string[] $files
     * @param bool $checkValidity
     */
    public static function mergeContentInDockerComposeFiles($content, array $files, bool $checkValidity = true): void
    {
        $tmpFile = __DIR__ . '/tmp-merge-content-file.yml';
        $tmpMergedFile = __DIR__ . '/tmp-merged-content-file.yml';

        if (\is_array($content)) {
            $content = Yaml::dump($content, 256, 2, Yaml::DUMP_OBJECT_AS_MAP);
        }

        $fileSystem = new Filesystem();
        $fileSystem->dumpFile($tmpFile, $content);

        foreach ($files as $file) {
            if ($checkValidity) {
                YamlTools::mergeSuccessive([$file, $tmpFile], $tmpMergedFile);
                self::checkDockerComposeFileValidity($tmpMergedFile);
                $fileSystem->copy($tmpMergedFile, $file, true);
            } else {
                YamlTools::mergeTwoFiles($file, $tmpFile);
            }
        }

        $fileSystem->remove($tmpFile);
        if ($checkValidity) {
            $fileSystem->remove($tmpMergedFile);
        }
    }
}

<?php

namespace TheAentMachine\AentDockerCompose\Helper;

use Safe\Exceptions\FilesystemException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use TheAentMachine\Service\Enum\VolumeTypeEnum;
use TheAentMachine\Service\Environment\EnvVariable;
use TheAentMachine\Service\Service;
use TheAentMachine\Service\Volume\BindVolume;
use TheAentMachine\Service\Volume\NamedVolume;
use TheAentMachine\Service\Volume\TmpfsVolume;
use TheAentMachine\Service\Volume\Volume;
use TheAentMachine\Yaml\CommentedItem;
use TheAentMachine\YamlTools\YamlTools;
use function \Safe\file_get_contents;
use function \Safe\file_put_contents;
use function \Safe\unlink;

final class DockerComposeHelper
{
    public const VERSION = '3.7';

    /**
     * @param Service $service
     * @param string[] $envFileNames
     * @param string $version
     * @return mixed[]
     */
    public static function dockerComposeServiceSerialize(Service $service, array $envFileNames = [], string $version = self::VERSION): array
    {
        $portMap = function (array $port): string {
            return $port['source'] . ':' . $port['target'];
        };
        $envMapDockerCompose = self::getEnvironmentVariablesForDockerCompose($service);
        $envMap = function (EnvVariable $e) {
            if ($e->getComment() !== null) {
                return new CommentedItem($e->getValue(), $e->getComment());
            }
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
                    'ports' => \array_map($portMap, $service->getPorts()),
                    'labels' => $service->getLabels(),
                    'environment' => \array_map($envMap, $envMapDockerCompose),
                    'volumes' => \array_map($volumeMap, $service->getVolumes()),
                ]),
            ],
        ];
        if ($envFileNames) {
            $dockerService['services'][$service->getServiceName()]['env_file'] = $envFileNames;
        }
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
     * @throws FilesystemException
     */
    public static function checkDockerComposeFileValidity(string $pathname): void
    {
        // We cannot check the env_file directive as it is reading env files that are not available in the temporary directory.
        // Plus we cannot check the depends_on with undefined classes.
        // Therefore, we need to remove the env_file before checking.
        $strFile = file_get_contents($pathname);
        $content = Yaml::parse($strFile);
        if (isset($content['services'])) {
            $services = $content['services'];
            foreach ($services as $key => &$service) {
                unset($service['env_file'], $service['depends_on']);
            }
            $content['services'] = $services;
        }
        file_put_contents('/tmp/docker-compose-check.yml', YamlTools::dump($content));
        $command = ['docker-compose', '-f', '/tmp/docker-compose-check.yml', 'config', '-q'];
        $process = new Process($command);
        $process->enableOutput();
        $process->setTty(true);
        try {
            $process->mustRun();
        } finally {
            unlink('/tmp/docker-compose-check.yml');
        }
    }

    /**
     * Merge some yaml content into a docker-compose file (and check its validity, by default)
     * @param mixed[]|string $content
     * @param string $file
     * @param bool $checkValidity
     * @throws FilesystemException
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
     * @throws FilesystemException
     */
    public static function mergeContentInDockerComposeFiles($content, array $files, bool $checkValidity = true): void
    {
        if (\is_array($content)) {
            $content = YamlTools::dump($content);
        }
        if ($checkValidity) {
            $fileSystem = new Filesystem();
            $contentFile = $fileSystem->tempnam(\sys_get_temp_dir(), 'docker-compose-content-');
            $fileSystem->dumpFile($contentFile, $content);
            $tmpFiles = [];
            foreach ($files as $file) {
                $tmpFile = $fileSystem->tempnam(\sys_get_temp_dir(), 'docker-compose-tmp-');
                YamlTools::normalizeDockerCompose($file, $tmpFile);
                YamlTools::mergeTwoFiles($tmpFile, $contentFile);
                YamlTools::normalizeDockerCompose($tmpFile, $tmpFile);
                self::checkDockerComposeFileValidity($tmpFile);
                $tmpFiles[$file] = $tmpFile;
            }
            foreach ($files as $file) {
                $tmpFile = $tmpFiles[$file];
                $fileSystem->copy($tmpFile, $file, true);
            }
            $fileSystem->remove($contentFile);
            $fileSystem->remove($tmpFiles);
        } else {
            foreach ($files as $file) {
                YamlTools::mergeContentIntoFile($content, $file);
            }
        }
    }

    /**
     * @param Service $service
     * @return array<string,EnvVariable>
     */
    public static function getEnvironmentVariablesForDockerCompose(Service $service): array
    {
        return $service->getAllContainerEnvVariable() + $service->getAllImageEnvVariable();
    }

    /**
     * @param Service $service
     * @return array<string,EnvVariable>
     */
    public static function getEnvironmentVariablesForDotEnv(Service $service): array
    {
        return $service->getAllSharedSecret() + $service->getAllSharedEnvVariable();
    }
}

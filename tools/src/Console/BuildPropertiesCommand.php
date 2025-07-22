<?php

declare(strict_types=1);

namespace Remorhaz\UCD\Tool\Console;

use LogicException;
use PhpParser\PrettyPrinter\Standard;
use Remorhaz\UCD\Tool\PropertyBuilder;
use RuntimeException;
use SplFileObject;
use Safe;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function is_string;

#[AsCommand('build')]
final class BuildPropertiesCommand extends Command
{
    private const OPTION_TARGET_ROOT_PATH = 'target-root-path';
    private const OPTION_TARGET_INDEX_ROOT_PATH = 'target-index-root-path';
    private const OPTION_SOURCE_ROOT_PATH = 'source-root-path';
    private const OPTION_SOURCE_UNICODE_DATA = 'source-unicode-data';
    private const OPTION_SOURCE_SCRIPTS = 'source-scripts';
    private const OPTION_SOURCE_PROP_LIST = 'source-prop-list';
    private const OPTION_SOURCE_DERIVED_CORE_PROPERTIES = 'source-derived-core-properties';

    #[\Override]
    protected function configure(): void
    {
        $this
            ->setDescription('Makes Unicode properties available to regular expressions')
            ->addOption(
                self::OPTION_TARGET_ROOT_PATH,
                null,
                InputOption::VALUE_REQUIRED,
                'Root path for generated files (without tailing slash)',
                $this->getDefaultTargetRootPath()
            )
            ->addOption(
                self::OPTION_TARGET_INDEX_ROOT_PATH,
                null,
                InputOption::VALUE_REQUIRED,
                'Root path for generated index file (without tailing slash)',
                $this->getDefaultTargetIndexRootPath()
            )
            ->addOption(
                self::OPTION_SOURCE_ROOT_PATH,
                null,
                InputOption::VALUE_REQUIRED,
                'Location of normative Unicode files (without tailing slash)',
                $this->getDefaultSourceRootPath()
            )
            ->addOption(
                self::OPTION_SOURCE_UNICODE_DATA,
                null,
                InputOption::VALUE_REQUIRED,
                'Location of UnicodeData.txt (relative to source root path, with heading slash)',
                '/UnicodeData.txt'
            )->addOption(
                self::OPTION_SOURCE_SCRIPTS,
                null,
                InputOption::VALUE_REQUIRED,
                'Location of Scripts.txt (relative to source root path, with heading slash)',
                '/Scripts.txt'
            )->addOption(
                self::OPTION_SOURCE_PROP_LIST,
                null,
                InputOption::VALUE_REQUIRED,
                'Location of PropList.txt (relative to source root path, with heading slash)',
                '/PropList.txt'
            )->addOption(
                self::OPTION_SOURCE_DERIVED_CORE_PROPERTIES,
                null,
                InputOption::VALUE_REQUIRED,
                'Location of DerivedCoreProperties.txt (relative to source root path, with heading slash)',
                '/DerivedCoreProperties.txt',
            );
    }

    private function getDefaultTargetRootPath(): string
    {
        return $this->getRealPath(
            __DIR__ . '/../../../src',
            'Default target root path not detected',
        );
    }

    private function getDefaultTargetIndexRootPath(): string
    {
        return $this->getRealPath(
            __DIR__ . '/../../../config',
            'Default target index file root path not detected',
        );
    }

    private function getRealPath(string $path, string $errorMessage): string
    {
        try {
            return Safe\realpath($path);
        } catch (Throwable $e) {
            throw new LogicException($errorMessage, previous: $e);
        }
    }

    private function getDefaultSourceRootPath(): string
    {
        return $this->getRealPath(
            __DIR__ . '/../../data',
            'Default source root path not detected',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->getApplication()?->getName() ?? 'unknown');
        $propertyBuilder = new PropertyBuilder(new Standard());
        $this->parseUnicodeData($propertyBuilder, $input, $output);
        $this->parseScripts($propertyBuilder, $input, $output);
        $this->parsePropList($propertyBuilder, $input, $output);
        $this->parseDerivedCoreProperties($propertyBuilder, $input, $output);
        $this->buildFiles($propertyBuilder, $input, $output);

        return 0;
    }

    private function parseUnicodeData(
        PropertyBuilder $propertyBuilder,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $progressBar = new ProgressBar($output);
        $progressIndicator = new ProgressIndicator($output);

        $output->writeln(' Parsing UnicodeData.txt...');
        $unicodeData = new SplFileObject($this->getSourceUnicodeData($input));
        $progressBar->setMaxSteps($unicodeData->getSize());
        $onParseProgress = function (int $byteCount) use ($progressBar): void {
            $progressBar->advance($byteCount);
        };
        $progressBar->start();
        $propertyBuilder->parseUnicodeData($unicodeData, $onParseProgress);
        $progressBar->finish();
        $progressBar->clear();
        $output->writeln(" {$unicodeData->getSize()} bytes of UnicodeData.txt parsed");
        $this->fetchRangeSets($output, $propertyBuilder, $progressBar);

        $progressIndicator->start('Building UnicodeData derivatives...');
        $onBuildProgress = function () use ($progressIndicator): void {
            $progressIndicator->advance();
        };
        $propertyBuilder->buildUnicodeDataDerivatives($onBuildProgress);
        $progressIndicator->finish('UnicodeData derivatives are built.');
        $this->fetchRangeSets($output, $propertyBuilder, $progressBar);
    }

    private function fetchRangeSets(
        OutputInterface $output,
        PropertyBuilder $propertyBuilder,
        ProgressBar $progressBar,
    ): void {
        $output->writeln(' Creating range sets from buffer...');
        $bufferSize = $propertyBuilder->getRangeBufferSize();
        $progressBar->setMaxSteps($bufferSize);
        $onFetchProgress = function (int $rangeSetIndex) use ($progressBar): void {
            $progressBar->setProgress($rangeSetIndex);
        };
        $progressBar->start();
        $propertyBuilder->fetchBufferedRangeSets($onFetchProgress);
        $progressBar->finish();
        $progressBar->clear();
        $output->writeln(" $bufferSize range sets created");
    }

    private function getSourceRootPath(InputInterface $input): string
    {
        $optionName = self::OPTION_SOURCE_ROOT_PATH;
        /** @psalm-var mixed $sourceRootPath */
        $sourceRootPath = $input->getOption($optionName);

        return is_string($sourceRootPath)
            ? $sourceRootPath
            : throw new RuntimeException("Option --$optionName must be a string");
    }

    private function getTargetRootPath(InputInterface $input): string
    {
        $optionName = self::OPTION_TARGET_ROOT_PATH;
        /** @psalm-var mixed $targetRootPath */
        $targetRootPath = $input->getOption($optionName);

        return is_string($targetRootPath)
            ? $targetRootPath
            : throw new RuntimeException("Option --$optionName must be a string");
    }

    private function getTargetIndexRootPath(InputInterface $input): string
    {
        $optionName = self::OPTION_TARGET_INDEX_ROOT_PATH;
        /** @psalm-var mixed $targetRootPath */
        $targetRootPath = $input->getOption($optionName);

        return is_string($targetRootPath)
            ? $targetRootPath
            : throw new RuntimeException("Option --$optionName must be a string");
    }

    private function getSourceUnicodeData(InputInterface $input): string
    {
        return $this->getSourceFile(self::OPTION_SOURCE_UNICODE_DATA, $input);
    }

    private function getSourceScripts(InputInterface $input): string
    {
        return $this->getSourceFile(self::OPTION_SOURCE_SCRIPTS, $input);
    }

    private function getSourcePropList(InputInterface $input): string
    {
        return $this->getSourceFile(self::OPTION_SOURCE_PROP_LIST, $input);
    }

    private function getSourceDerivedCoreProperties(InputInterface $input): string
    {
        return $this->getSourceFile(self::OPTION_SOURCE_DERIVED_CORE_PROPERTIES, $input);
    }

    private function getSourceFile(string $optionName, InputInterface $input): string
    {
        /** @psalm-var mixed $sourceScripts */
        $sourceScripts = $input->getOption($optionName);

        return is_string($sourceScripts)
            ? $this->getSourceRootPath($input) . $sourceScripts
            : throw new RuntimeException("Option --$optionName must be a string");
    }

    private function parseScripts(
        PropertyBuilder $propertyBuilder,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $progressBar = new ProgressBar($output);
        $progressIndicator = new ProgressIndicator($output);

        $output->writeln(' Parsing Scripts.txt...');

        $scripts = new SplFileObject($this->getSourceScripts($input));
        $progressBar->setMaxSteps($scripts->getSize());
        $onParseProgress = function (int $byteCount) use ($progressBar): void {
            $progressBar->advance($byteCount);
        };
        $progressBar->start();
        $propertyBuilder->parseScripts($scripts, $onParseProgress);
        $progressBar->finish();
        $progressBar->clear();
        $output->writeln(" {$scripts->getSize()} bytes of Scripts.txt parsed");
        $this->fetchRangeSets($output, $propertyBuilder, $progressBar);

        $progressIndicator->start('Building Scripts derivatives...');
        $onBuildProgress = function () use ($progressIndicator): void {
            $progressIndicator->advance();
        };
        $propertyBuilder->buildScriptsDerivatives($onBuildProgress);
        $progressIndicator->finish('Scripts derivatives are built.');
        $this->fetchRangeSets($output, $propertyBuilder, $progressBar);
    }

    private function parsePropList(
        PropertyBuilder $propertyBuilder,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $progressBar = new ProgressBar($output);

        $output->writeln(' Parsing PropList.txt...');
        $propList = new SplFileObject($this->getSourcePropList($input));
        $progressBar->setMaxSteps($propList->getSize());
        $onParseProgress = function (int $byteCount) use ($progressBar): void {
            $progressBar->advance($byteCount);
        };
        $progressBar->start();
        $propertyBuilder->parseProperties($propList, $onParseProgress);
        $progressBar->finish();
        $progressBar->clear();
        $output->writeln(" {$propList->getSize()} bytes of PropList.txt parsed");
        $this->fetchRangeSets($output, $propertyBuilder, $progressBar);
    }

    private function parseDerivedCoreProperties(
        PropertyBuilder $propertyBuilder,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $progressBar = new ProgressBar($output);

        $output->writeln(' Parsing DerivedCoreProperties.txt...');
        $derivedCoreProperties = new SplFileObject($this->getSourceDerivedCoreProperties($input));
        $progressBar->setMaxSteps($derivedCoreProperties->getSize());
        $onParseProgress = function (int $byteCount) use ($progressBar): void {
            $progressBar->advance($byteCount);
        };
        $progressBar->start();
        $propertyBuilder->parseProperties($derivedCoreProperties, $onParseProgress);
        $progressBar->finish();
        $progressBar->clear();
        $output->writeln(" {$derivedCoreProperties->getSize()} bytes of DerivedCoreProperties.txt parsed");
        $this->fetchRangeSets($output, $propertyBuilder, $progressBar);
    }

    private function buildFiles(
        PropertyBuilder $propertyBuilder,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $progressBar = new ProgressBar($output);

        $output->writeln(' Writing target files...');
        $progressBar->setMaxSteps($propertyBuilder->getFileCount());
        $onWriteProgress = function () use ($progressBar): void {
            $progressBar->advance();
        };
        $progressBar->start();
        $propertyBuilder->writeFiles(
            $this->getTargetIndexRootPath($input),
            $this->getTargetRootPath($input),
            $onWriteProgress,
        );
        $progressBar->finish();
        $progressBar->clear();
        $output->writeln(" {$propertyBuilder->getFileCount()} target files written");
    }
}

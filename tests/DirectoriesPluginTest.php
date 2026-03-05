<?php

declare(strict_types=1);

namespace Niklan\ComposerDirectories\Tests;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\RootPackageInterface;
use Composer\Script\Event;
use Composer\Util\Filesystem;
use Niklan\ComposerDirectories\DirectoriesPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DirectoriesPlugin::class)]
final class DirectoriesPluginTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/composer-directories-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->removeDirectory($this->tempDir);
    }

    public function testDirectoriesWithStringEntries(): void
    {
        $plugin = $this->createActivatedPlugin([
            'ensure-directories' => [
                'var/log',
                'var/cache',
            ],
        ]);

        $plugin->onPostCommand($this->createMock(Event::class));

        self::assertDirectoryExists($this->tempDir . '/var/log');
        self::assertDirectoryExists($this->tempDir . '/var/cache');
    }

    public function testDirectoriesWithObjectEntries(): void
    {
        $plugin = $this->createActivatedPlugin([
            'ensure-directories' => [
                ['path' => 'var/files', 'permissions' => '0755'],
            ],
        ]);

        $plugin->onPostCommand($this->createMock(Event::class));

        self::assertDirectoryExists($this->tempDir . '/var/files');
    }

    public function testDirectoriesSkipsExisting(): void
    {
        mkdir($this->tempDir . '/existing', 0777, true);

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::once())
            ->method('write')
            ->with(
                self::stringContains('Directory exists'),
                true,
                IOInterface::VERBOSE,
            );

        $plugin = $this->createActivatedPlugin([
            'ensure-directories' => ['existing'],
        ], $io);

        $plugin->onPostCommand($this->createMock(Event::class));

        self::assertDirectoryExists($this->tempDir . '/existing');
    }

    public function testEmptyConfigDoesNothing(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::never())->method('write');

        $plugin = $this->createActivatedPlugin([], $io);
        $plugin->onPostCommand($this->createMock(Event::class));
    }

    public function testDirectoriesWritesOutput(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::once())
            ->method('write')
            ->with(self::stringContains('var/log'));

        $plugin = $this->createActivatedPlugin([
            'ensure-directories' => ['var/log'],
        ], $io);

        $plugin->onPostCommand($this->createMock(Event::class));
    }

    public function testDirectoriesBlocksPathTraversal(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::never())->method('write');
        $io->expects(self::exactly(3))->method('writeError');

        $plugin = $this->createActivatedPlugin([
            'ensure-directories' => [
                '../outside',
                '../../etc/evil',
                'var/log/../../..',
            ],
        ], $io);

        $plugin->onPostCommand($this->createMock(Event::class));

        self::assertDirectoryDoesNotExist(\dirname($this->tempDir) . '/outside');
        self::assertDirectoryDoesNotExist($this->tempDir . '/var');
    }

    public function testDirectoriesAllowsNestedTraversal(): void
    {
        $plugin = $this->createActivatedPlugin([
            'ensure-directories' => [
                'var/log/../cache',
            ],
        ]);

        $plugin->onPostCommand($this->createMock(Event::class));

        self::assertDirectoryExists($this->tempDir . '/var/cache');
    }

    public function testSymlinkCreation(): void
    {
        mkdir($this->tempDir . '/var/files', 0777, true);

        $plugin = $this->createActivatedPlugin([
            'symlinks' => [
                'var/files' => 'web/sites/default/files',
            ],
        ]);

        $plugin->onPostCommand($this->createMock(Event::class));

        $linkPath = $this->tempDir . '/web/sites/default/files';
        self::assertTrue(\is_link($linkPath));
        self::assertSame(
            \realpath($this->tempDir . '/var/files'),
            \realpath($linkPath),
        );
    }

    public function testSymlinkReplacesExistingLink(): void
    {
        mkdir($this->tempDir . '/target-old', 0777, true);
        mkdir($this->tempDir . '/target-new', 0777, true);
        \symlink($this->tempDir . '/target-old', $this->tempDir . '/my-link');

        $plugin = $this->createActivatedPlugin([
            'symlinks' => [
                'target-new' => 'my-link',
            ],
        ]);

        $plugin->onPostCommand($this->createMock(Event::class));

        self::assertSame(
            \realpath($this->tempDir . '/target-new'),
            \realpath($this->tempDir . '/my-link'),
        );
    }

    public function testSymlinkSkipsExistingNonLink(): void
    {
        mkdir($this->tempDir . '/target', 0777, true);
        mkdir($this->tempDir . '/existing-dir', 0777, true);

        $io = $this->createMock(IOInterface::class);
        $io->expects(self::never())->method('write');
        $io->expects(self::once())->method('writeError');

        $plugin = $this->createActivatedPlugin([
            'symlinks' => [
                'target' => 'existing-dir',
            ],
        ], $io);

        $plugin->onPostCommand($this->createMock(Event::class));

        self::assertFalse(\is_link($this->tempDir . '/existing-dir'));
    }

    public function testSymlinkBlocksPathTraversal(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects(self::never())->method('write');
        $io->expects(self::exactly(2))->method('writeError');

        $plugin = $this->createActivatedPlugin([
            'symlinks' => [
                '../outside' => 'link1',
                'target' => '../outside-link',
            ],
        ], $io);

        $plugin->onPostCommand($this->createMock(Event::class));
    }

    public function testDirectoriesCreatedBeforeSymlinks(): void
    {
        $plugin = $this->createActivatedPlugin([
            'ensure-directories' => ['var/files'],
            'symlinks' => [
                'var/files' => 'web/files',
            ],
        ]);

        $plugin->onPostCommand($this->createMock(Event::class));

        self::assertDirectoryExists($this->tempDir . '/var/files');
        self::assertTrue(\is_link($this->tempDir . '/web/files'));
        self::assertSame(
            \realpath($this->tempDir . '/var/files'),
            \realpath($this->tempDir . '/web/files'),
        );
    }

    /** @param array<string, mixed> $extra */
    private function createActivatedPlugin(array $extra, IOInterface|null $io = null): DirectoriesPlugin
    {
        $package = $this->createMock(RootPackageInterface::class);
        $package->method('getExtra')->willReturn($extra);

        $config = $this->createMock(Config::class);
        $config->method('get')->with('vendor-dir')->willReturn($this->tempDir . '/vendor');

        $composer = $this->createMock(Composer::class);
        $composer->method('getPackage')->willReturn($package);
        $composer->method('getConfig')->willReturn($config);

        $io ??= $this->createMock(IOInterface::class);

        $plugin = new DirectoriesPlugin();
        $plugin->activate($composer, $io);

        return $plugin;
    }
}

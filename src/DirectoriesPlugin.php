<?php

declare(strict_types=1);

namespace Niklan\ComposerDirectories;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

/**
 * Composer plugin for managing project file structure.
 *
 * Ensures directories exist and creates symlinks after install/update.
 * Directories are always processed before symlinks.
 *
 * Configuration example:
 *
 *   "extra": {
 *       "ensure-directories": [
 *           "var/log",
 *           {"path": "var/files/private", "permissions": "0700"}
 *       ],
 *       "symlinks": {
 *           "var/files/public": "web/sites/default/files"
 *       }
 *   }
 */
final class DirectoriesPlugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;

    private IOInterface $io;

    private Filesystem $filesystem;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->filesystem = new Filesystem();
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostCommand',
            ScriptEvents::POST_UPDATE_CMD => 'onPostCommand',
        ];
    }

    public function onPostCommand(Event $event): void
    {
        /** @var string $vendorDir */
        $vendorDir = $this->composer->getConfig()->get('vendor-dir');
        $baseDir = $this->filesystem->normalizePath(\dirname($vendorDir));
        $extra = $this->composer->getPackage()->getExtra();

        $this->ensureDirectories($baseDir, $extra);
        $this->createSymlinks($baseDir, $extra);
    }

    /**
     * @param array<mixed> $extra
     */
    private function ensureDirectories(string $baseDir, array $extra): void
    {
        /** @var list<string|array{path: string, permissions?: string}> $directories */
        $directories = $extra['ensure-directories'] ?? [];

        // Default matches Drupal's CHMOD_DIRECTORY (0775).
        // @see https://git.drupalcode.org/project/drupal/-/blob/aea47fe1b2bf7945548988ca14d069b83273f2a5/core/lib/Drupal/Core/File/FileSystem.php#L27
        foreach ($directories as $entry) {
            if (\is_array($entry)) {
                $path = $entry['path'];
                $permissions = \intval($entry['permissions'] ?? '0775', 8);
            } else {
                $path = $entry;
                $permissions = 0775;
            }

            $this->ensureDirectory($baseDir, $path, $permissions);
        }
    }

    private function ensureDirectory(string $baseDir, string $directory, int $permissions): void
    {
        $absolutePath = $this->filesystem->normalizePath($baseDir . '/' . $directory);

        if (!$this->isWithinBaseDir($absolutePath, $baseDir)) {
            $this->io->writeError(\sprintf(
                '  <warning>Skipped directory outside project root: %s</warning>',
                $directory,
            ));

            return;
        }

        if (\is_dir($absolutePath)) {
            return;
        }

        \mkdir($absolutePath, $permissions, true);

        $this->io->write(\sprintf(
            '  Directory created: <info>%s</info> (%s)',
            $directory,
            '0' . \decoct($permissions),
        ));
    }

    /**
     * @param array<mixed> $extra
     */
    private function createSymlinks(string $baseDir, array $extra): void
    {
        /** @var array<string, string> $symlinks */
        $symlinks = $extra['symlinks'] ?? [];

        foreach ($symlinks as $target => $link) {
            $this->createSymlink($baseDir, $target, $link);
        }
    }

    private function createSymlink(string $baseDir, string $target, string $link): void
    {
        $absoluteLink = $this->filesystem->normalizePath($baseDir . '/' . $link);
        $absoluteTarget = $this->filesystem->normalizePath($baseDir . '/' . $target);

        if (!$this->validateSymlinkPaths($absoluteLink, $absoluteTarget, $baseDir, $target, $link)) {
            return;
        }

        $linkDir = \dirname($absoluteLink);
        $this->filesystem->ensureDirectoryExists($linkDir);

        if (!$this->prepareLinkPath($absoluteLink, $link)) {
            return;
        }

        $relativeTarget = $this->filesystem->findShortestPath($linkDir, $absoluteTarget, true);

        if (PHP_OS_FAMILY === 'Windows' && \is_dir($absoluteTarget)) {
            $this->filesystem->junction($absoluteTarget, $absoluteLink);
        } else {
            \symlink($relativeTarget, $absoluteLink);
        }

        $this->io->write(\sprintf(
            '  Symlink created: <info>%s</info> -> <info>%s</info>',
            $link,
            $relativeTarget,
        ));
    }

    private function validateSymlinkPaths(
        string $absoluteLink,
        string $absoluteTarget,
        string $baseDir,
        string $target,
        string $link,
    ): bool {
        if (!$this->isWithinBaseDir($absoluteLink, $baseDir)) {
            $this->io->writeError(\sprintf(
                '  <warning>Skipped symlink outside project root: %s</warning>',
                $link,
            ));

            return false;
        }

        if (!$this->isWithinBaseDir($absoluteTarget, $baseDir)) {
            $this->io->writeError(\sprintf(
                '  <warning>Skipped symlink target outside project root: %s</warning>',
                $target,
            ));

            return false;
        }

        return true;
    }

    /**
     * Removes an existing symlink or reports a conflict.
     *
     * @return bool Whether the path is clear for symlink creation.
     */
    private function prepareLinkPath(string $absoluteLink, string $link): bool
    {
        if (\is_link($absoluteLink)) {
            try {
                $this->filesystem->unlink($absoluteLink);
            } catch (\RuntimeException) {
                // The link may have been removed between is_link() and
                // unlink() due to a stale PHP stat cache or another plugin
                // modifying the filesystem during the same event.
            }

            return true;
        }

        if (\file_exists($absoluteLink)) {
            $this->io->writeError(\sprintf(
                '  <warning>"%s" exists and is not a symlink, skipping</warning>',
                $link,
            ));

            return false;
        }

        return true;
    }

    private function isWithinBaseDir(string $path, string $baseDir): bool
    {
        return \str_starts_with($path, $baseDir . '/') || $path === $baseDir;
    }
}

<?php

declare(strict_types=1);

namespace Rector\PostRector\Application;

use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\Contract\DependencyInjection\ResetableInterface;
use Rector\PostRector\Contract\Rector\PostRectorInterface;
use Rector\PostRector\Rector\ClassRenamingPostRector;
use Rector\PostRector\Rector\DocblockNameImportingPostRector;
use Rector\PostRector\Rector\NameImportingPostRector;
use Rector\PostRector\Rector\UnusedImportRemovingPostRector;
use Rector\PostRector\Rector\UseAddingPostRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Skipper\Skipper\Skipper;

final class PostFileProcessor implements ResetableInterface
{
    /**
     * @var PostRectorInterface[]
     */
    private array $postRectors = [];

    public function __construct(
        private readonly Skipper $skipper,
        private readonly UseAddingPostRector $useAddingPostRector,
        private readonly NameImportingPostRector $nameImportingPostRector,
        private readonly ClassRenamingPostRector $classRenamingPostRector,
        private readonly DocblockNameImportingPostRector $docblockNameImportingPostRector,
        private readonly UnusedImportRemovingPostRector $unusedImportRemovingPostRector,
    ) {
    }

    public function reset(): void
    {
        $this->postRectors = [];
    }

    /**
     * @param Stmt[] $stmts
     * @return Stmt[]
     */
    public function traverse(array $stmts, string $filePath): array
    {
        foreach ($this->getPostRectors() as $postRector) {
            if ($this->shouldSkipPostRector($postRector, $filePath, $stmts)) {
                continue;
            }

            $nodeTraverser = new NodeTraverser();
            $nodeTraverser->addVisitor($postRector);
            $stmts = $nodeTraverser->traverse($stmts);
        }

        return $stmts;
    }

    /**
     * @param Stmt[] $stmts
     */
    private function shouldSkipPostRector(PostRectorInterface $postRector, string $filePath, array $stmts): bool
    {
        if (! $postRector->shouldTraverse($stmts)) {
            return true;
        }

        if ($this->skipper->shouldSkipElementAndFilePath($postRector, $filePath)) {
            return true;
        }

        // skip renaming if rename class rector is skipped
        return $postRector instanceof ClassRenamingPostRector && $this->skipper->shouldSkipElementAndFilePath(
            RenameClassRector::class,
            $filePath
        );
    }

    /**
     * Load on the fly, to allow test reset with different configuration
     * @return PostRectorInterface[]
     */
    private function getPostRectors(): array
    {
        if ($this->postRectors !== []) {
            return $this->postRectors;
        }

        $isNameImportingEnabled = SimpleParameterProvider::provideBoolParameter(Option::AUTO_IMPORT_NAMES);
        $isDocblockNameImportingEnabled = SimpleParameterProvider::provideBoolParameter(
            Option::AUTO_IMPORT_DOC_BLOCK_NAMES
        );

        $isRemovingUnusedImportsEnabled = SimpleParameterProvider::provideBoolParameter(Option::REMOVE_UNUSED_IMPORTS);

        // sorted by priority, to keep removed imports in order
        $postRectors = [$this->classRenamingPostRector];

        // import names
        if ($isNameImportingEnabled) {
            $postRectors[] = $this->nameImportingPostRector;
        }

        // import docblocks
        if ($isDocblockNameImportingEnabled) {
            $postRectors[] = $this->docblockNameImportingPostRector;
        }

        $postRectors[] = $this->useAddingPostRector;

        if ($isRemovingUnusedImportsEnabled) {
            $postRectors[] = $this->unusedImportRemovingPostRector;
        }

        $this->postRectors = $postRectors;

        return $this->postRectors;
    }
}

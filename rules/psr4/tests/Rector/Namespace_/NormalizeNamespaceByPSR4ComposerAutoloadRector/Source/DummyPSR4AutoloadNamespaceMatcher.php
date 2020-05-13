<?php

declare(strict_types=1);

namespace Rector\PSR4\Tests\Rector\Namespace_\NormalizeNamespaceByPSR4ComposerAutoloadRector\Source;

use PhpParser\Node;

final class DummyPSR4AutoloadNamespaceMatcher implements \Rector\PSR4\Contract\PSR4AutoloadNamespaceMatcherInterface
{
    public function getExpectedNamespace(Node $node): ?string
    {
        return 'Rector\PSR4\Tests\Rector\Namespace_\NormalizeNamespaceByPSR4ComposerAutoloadRector\Fixture';
    }
}

<?php

declare(strict_types=1);

namespace Rector\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use Rector\Bridge\SetRectorsResolver;
use Rector\Contract\Rector\RectorInterface;
use Rector\Set\ValueObject\SetList;

final class SetRectorsResolverTest extends TestCase
{
    private SetRectorsResolver $setRectorsResolver;

    protected function setUp(): void
    {
        $this->setRectorsResolver = new SetRectorsResolver();
    }


    public function test(): void
    {
        $rectorRules = $this->setRectorsResolver->resolveFromFilePath(SetList::PHP_73);

        $this->assertCount(10, $rectorRules);

        foreach ($rectorRules as $rectorRule) {
            $this->assertTrue(is_a($rectorRule, RectorInterface::class, true));
        }
    }

    public function testResolveWithConfiguration()
    {
        $rectorRulesWithConfiguration = $this->setRectorsResolver->resolveFromFilePath(SetList::PHP_73);

        dump($rectorRulesWithConfiguration);
        die;
    }
}

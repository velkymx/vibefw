<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\BuiltinTools;
use Fw\Aux\Feature;
use Fw\Aux\FeatureIndex;
use Fw\Aux\Tool;
use Fw\Aux\WorkflowResult;
use PHPUnit\Framework\TestCase;

final class BuiltinToolsTest extends TestCase
{
    public function testListFeaturesToolShape(): void
    {
        $tool = BuiltinTools::listFeatures(new FeatureIndex());

        $this->assertInstanceOf(Tool::class, $tool);
        $this->assertSame('list_features', $tool->name);
        $this->assertSame('object', $tool->inputSchema['type']);
        $this->assertSame([], $tool->abilities);
        $this->assertTrue($tool->annotations['readOnlyHint'] ?? false);
    }

    public function testListFeaturesHandlerReturnsEntries(): void
    {
        $index = (new FeatureIndex())
            ->with(new Feature('home', 'Homepage', '/'))
            ->with(new Feature('admin', 'Admin', '/admin', ['admin']));

        $tool = BuiltinTools::listFeatures($index);
        $result = ($tool->handler)([]);

        $this->assertInstanceOf(WorkflowResult::class, $result);
        $this->assertFalse($result->isError);
        $this->assertCount(2, $result->completed);
        $this->assertSame('home', $result->completed[0]['name']);
        $this->assertSame('/admin', $result->completed[1]['url']);
    }

    public function testListFeaturesHandlerReflectsLiveIndexChanges(): void
    {
        $index = new FeatureIndex();
        $tool = BuiltinTools::listFeatures($index);

        $result = ($tool->handler)([]);
        $this->assertSame([], $result->completed);
    }

    public function testListFeaturesIgnoresInput(): void
    {
        $index = (new FeatureIndex())->with(new Feature('x', 'X', '/x'));
        $tool = BuiltinTools::listFeatures($index);

        $result = ($tool->handler)(['extra' => 'ignored']);

        $this->assertCount(1, $result->completed);
    }
}

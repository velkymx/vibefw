<?php

declare(strict_types=1);

namespace Fw\Tests\Unit\Aux;

use Fw\Aux\AuxDoctor;
use Fw\Aux\AuxStats;
use Fw\Aux\Feature;
use Fw\Aux\FeatureIndex;
use Fw\Aux\Tool;
use Fw\Aux\ToolRegistry;
use Fw\Aux\WorkflowResult;
use PHPUnit\Framework\TestCase;

final class AuxDoctorTest extends TestCase
{
    private string $statsPath;

    protected function setUp(): void
    {
        $this->statsPath = sys_get_temp_dir() . '/fw_doctor_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->statsPath)) {
            unlink($this->statsPath);
        }
    }

    public function testEmptySurfaceReportsLevelZero(): void
    {
        $doctor = new AuxDoctor(
            new ToolRegistry(),
            new FeatureIndex(),
            new AuxStats($this->statsPath),
            notificationChannels: [],
        );

        $report = $doctor->audit();

        $this->assertSame(0, $report->level);
        $this->assertGreaterThan(0, $report->total);
        $this->assertSame(0, $report->score);
    }

    public function testUserToolElevatesToLevelOne(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->makeTool('custom_tool'));

        $doctor = new AuxDoctor(
            $registry,
            new FeatureIndex(),
            new AuxStats($this->statsPath),
            notificationChannels: [],
        );

        $this->assertSame(1, $doctor->audit()->level);
    }

    public function testFeatureIndexAndNotificationsReachLevelTwo(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->makeTool('custom_tool'));

        $features = (new FeatureIndex())->with(new Feature(
            name: 'posts',
            description: 'Browse posts',
            url: '/posts',
        ));

        $doctor = new AuxDoctor(
            $registry,
            $features,
            new AuxStats($this->statsPath),
            notificationChannels: ['log'],
        );

        $this->assertSame(2, $doctor->audit()->level);
    }

    public function testBudgetAndStatsReachLevelThree(): void
    {
        $registry = new ToolRegistry();
        $registry->register($this->makeTool('custom_tool', budget: ['estimated_duration_ms' => 100]));

        $features = (new FeatureIndex())->with(new Feature(
            name: 'posts',
            description: 'Browse posts',
            url: '/posts',
        ));

        $stats = new AuxStats($this->statsPath);
        $stats->record('custom_tool', 42.5, false);

        $doctor = new AuxDoctor(
            $registry,
            $features,
            $stats,
            notificationChannels: ['log'],
        );

        $report = $doctor->audit();
        $this->assertSame(3, $report->level);
        $this->assertSame($report->total, $report->score);
    }

    public function testBuiltinToolsDoNotCountAsUserTools(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new Tool(
            name: 'list_features',
            description: 'built-in',
            inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
            handler: fn(array $i): WorkflowResult => WorkflowResult::success(),
        ));

        $doctor = new AuxDoctor(
            $registry,
            new FeatureIndex(),
            new AuxStats($this->statsPath),
            notificationChannels: [],
        );

        $this->assertSame(0, $doctor->audit()->level);
    }

    public function testChecksCarryHintsForFailedItems(): void
    {
        $doctor = new AuxDoctor(
            new ToolRegistry(),
            new FeatureIndex(),
            new AuxStats($this->statsPath),
            notificationChannels: [],
        );

        $report = $doctor->audit();

        foreach ($report->checks as $check) {
            $this->assertFalse($check['passed']);
            $this->assertNotSame('', $check['hint']);
        }
    }

    private function makeTool(string $name, array $budget = []): Tool
    {
        return new Tool(
            name: $name,
            description: 'custom',
            inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
            handler: fn(array $i): WorkflowResult => WorkflowResult::success(),
            budget: $budget,
        );
    }
}

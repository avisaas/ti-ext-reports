<?php

declare(strict_types=1);

namespace IgniterLabs\Reports\Tests\Models;

use Igniter\Cart\Models\Order;
use Igniter\Flame\Exception\FlashException;
use Igniter\System\Classes\ExtensionManager;
use IgniterLabs\Reports\Models\ReportBuilder;
use IgniterLabs\Reports\ReportRules\OrderRule;
use Illuminate\Pagination\LengthAwarePaginator;

it('configures report builder model correctly', function(): void {
    $reportBuilder = new ReportBuilder;

    expect($reportBuilder->getTable())->toBe('reports_builder')
        ->and($reportBuilder->timestamps)->toBeTrue()
        ->and($reportBuilder->getCasts()['rule_data'])->toBe('array')
        ->and($reportBuilder->getCasts()['columns'])->toBe('array')
        ->and($reportBuilder->getGuarded())->toBeEmpty();
});

it('gets dropdown options correctly', function(): void {
    ReportBuilder::factory()->count(3)->create(['name' => 'Test Report']);

    $options = ReportBuilder::getDropdownOptions();

    expect($options)->toBeCollection()
        ->and($options)->toHaveCount(3);
});

it('gets rule class options from manager', function(): void {
    app()->instance(ExtensionManager::class, mock(ExtensionManager::class, function($mock): void {
        $mock->shouldReceive('getRegistrationMethodValues')->with('registerReportRules')->andReturn([
            [OrderRule::class],
        ]);
    }));

    $options = (new ReportBuilder)->getRuleClassOptions();

    expect($options)->toHaveKey(OrderRule::class)
        ->and($options[OrderRule::class])->toBe('Orders');
});

it('gets rule object correctly', function(): void {
    $reportBuilder = new ReportBuilder;
    $reportBuilder->rule_class = OrderRule::class;

    expect($reportBuilder->getRuleObject())->toBeInstanceOf(OrderRule::class);
});

it('throws exception when getting table data with non-existent rule class', function(): void {
    $reportBuilder = new ReportBuilder;
    $reportBuilder->rule_class = 'NonExistentClass';

    expect(fn(): LengthAwarePaginator => $reportBuilder->getTableData('2023-01-01', '2023-01-31'))
        ->toThrow(FlashException::class);
});

it('throws exception when getting selected columns with non-existent rule class', function(): void {
    $reportBuilder = new ReportBuilder;
    $reportBuilder->rule_class = 'NonExistentClass';

    expect($reportBuilder->getSelectedColumns(...))
        ->toThrow(FlashException::class);
});

it('gets selected columns correctly', function(): void {
    app()->instance(ExtensionManager::class, mock(ExtensionManager::class, function($mock): void {
        $mock->shouldReceive('getRegistrationMethodValues')->with('registerReportRules')->andReturn([
            [OrderRule::class],
        ]);
    }));
    app()->instance(OrderRule::class, mock(OrderRule::class, function($mock): void {
        $mock->shouldReceive('defineColumns')->andReturn([
            'order_id' => ['title' => 'Order ID'],
            'order_total' => ['title' => 'Total'],
            'order_date' => ['title' => 'Date'],
        ]);
    }));

    $reportBuilder = new ReportBuilder;
    $reportBuilder->rule_class = OrderRule::class;
    $reportBuilder->columns = ['order_id', 'order_total'];

    $result = $reportBuilder->getSelectedColumns();

    expect($result)->toHaveKeys(['order_id', 'order_total'])
        ->and($result)->not->toHaveKey('order_date');
});

it('gets all columns when no specific columns are selected', function(): void {
    app()->instance(ExtensionManager::class, mock(ExtensionManager::class, function($mock): void {
        $mock->shouldReceive('getRegistrationMethodValues')->with('registerReportRules')->andReturn([
            [OrderRule::class],
        ]);
    }));
    app()->instance(OrderRule::class, mock(OrderRule::class, function($mock): void {
        $mock->shouldReceive('defineColumns')->andReturn([
            'order_id' => ['title' => 'Order ID'],
            'order_total' => ['title' => 'Total'],
            'order_date' => ['title' => 'Date'],
        ]);
    }));

    $reportBuilder = new ReportBuilder;
    $reportBuilder->rule_class = OrderRule::class;
    $reportBuilder->columns = null;

    $result = $reportBuilder->getSelectedColumns();

    expect($result)->toHaveKeys(['order_id', 'order_total', 'order_date']);
});

it('applies rule data filters to table data', function(): void {
    Order::factory()->create(['order_type' => 'delivery', 'order_date' => now()->toDateString()]);
    Order::factory()->create(['order_type' => 'collection', 'order_date' => now()->toDateString()]);

    $reportBuilder = new ReportBuilder;
    $reportBuilder->rule_class = OrderRule::class;
    $reportBuilder->rule_data = [
        'condition' => 'AND',
        'rules' => [
            ['id' => 'order_type', 'field' => 'order_type', 'type' => 'string', 'operator' => 'in', 'value' => ['delivery']],
        ],
        'valid' => true,
    ];

    $result = $reportBuilder->getTableData(now()->subDay()->toDateString(), now()->addDay()->toDateString());

    expect($result->total())->toBe(1)
        ->and($result->first()['order_type'])->toBe('Delivery');
});

it('throws exception when rule data uses a field not defined in rule filters', function(): void {
    $reportBuilder = new ReportBuilder;
    $reportBuilder->rule_class = OrderRule::class;
    $reportBuilder->rule_data = [
        'condition' => 'AND',
        'rules' => [
            ['id' => 'order_id', 'field' => 'order_id', 'type' => 'integer', 'operator' => 'equal', 'value' => '1'],
        ],
    ];

    expect(fn(): LengthAwarePaginator => $reportBuilder->getTableData(now()->subDay()->toDateString(), now()->addDay()->toDateString()))
        ->toThrow(FlashException::class, sprintf(lang('igniterlabs.reports::default.alert_query_field_not_allowed'), 'order_id'));
});

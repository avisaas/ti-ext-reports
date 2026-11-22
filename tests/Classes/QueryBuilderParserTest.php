<?php

declare(strict_types=1);

namespace IgniterLabs\Reports\Tests\Classes;

use Igniter\Cart\Models\Order;
use Igniter\Flame\Database\Builder;
use Igniter\Flame\Exception\FlashException;
use IgniterLabs\Reports\Classes\QueryBuilderParser;
use Illuminate\Support\Carbon;

function makeQbpRule(string $field, string $operator, mixed $value = null, string $type = 'string'): array
{
    return [
        'id' => $field,
        'field' => $field,
        'type' => $type,
        'input' => 'text',
        'operator' => $operator,
        'value' => $value,
    ];
}

function parseQbpRules(array $rules, string $condition = 'AND', ?array $fields = null): Builder
{
    return (new QueryBuilderParser($fields))->parse([
        'condition' => $condition,
        'rules' => $rules,
        'valid' => true,
    ], Order::query());
}

function qbpWhereSql(Builder $query): string
{
    return str_replace('`', '', str($query->toSql())->after(' where ')->toString());
}

it('returns query untouched when there are no rules', function(array $ruleData): void {
    $query = Order::query();

    expect((new QueryBuilderParser)->parse($ruleData, $query))->toBe($query)
        ->and($query->getQuery()->wheres)->toBeEmpty();
})->with([
    'missing rules' => [['condition' => 'AND']],
    'rules not array' => [['condition' => 'AND', 'rules' => 'invalid']],
    'empty rules' => [['condition' => 'AND', 'rules' => []]],
]);

it('applies comparison operators', function(string $operator, string $sql, mixed $value, mixed $binding): void {
    $query = parseQbpRules([makeQbpRule('order_total', $operator, $value, 'double')]);

    expect(qbpWhereSql($query))->toBe('order_total '.$sql.' ?')
        ->and($query->getBindings())->toBe([$binding]);
})->with([
    'equal' => ['equal', '=', '10', '10'],
    'not equal' => ['not_equal', '!=', '10', '10'],
    'less' => ['less', '<', '10', '10'],
    'less or equal' => ['less_or_equal', '<=', '10', '10'],
    'greater' => ['greater', '>', '10', '10'],
    'greater or equal' => ['greater_or_equal', '>=', '10', '10'],
    'single item array is flattened' => ['equal', '=', ['10'], '10'],
]);

it('applies like operators with wildcards', function(string $operator, string $sql, string $binding): void {
    $query = parseQbpRules([makeQbpRule('email', $operator, 'test')]);

    expect(qbpWhereSql($query))->toBe('email '.$sql.' ?')
        ->and($query->getBindings())->toBe([$binding]);
})->with([
    'begins with' => ['begins_with', 'LIKE', 'test%'],
    'not begins with' => ['not_begins_with', 'NOT LIKE', 'test%'],
    'contains' => ['contains', 'LIKE', '%test%'],
    'not contains' => ['not_contains', 'NOT LIKE', '%test%'],
    'ends with' => ['ends_with', 'LIKE', '%test'],
    'not ends with' => ['not_ends_with', 'NOT LIKE', '%test'],
]);

it('applies operators that do not accept values', function(string $operator, string $sql, array $bindings): void {
    $query = parseQbpRules([makeQbpRule('email', $operator, 'ignored')]);

    expect(qbpWhereSql($query))->toBe($sql)
        ->and($query->getBindings())->toBe($bindings);
})->with([
    'is empty' => ['is_empty', 'email = ?', ['']],
    'is not empty' => ['is_not_empty', 'email != ?', ['']],
    'is null' => ['is_null', 'email is null', []],
    'is not null' => ['is_not_null', 'email is not null', []],
]);

it('applies array operators', function(string $operator, string $sql): void {
    $query = parseQbpRules([makeQbpRule('order_total', $operator, ['1', '2'], 'double')]);

    expect(qbpWhereSql($query))->toBe($sql)
        ->and($query->getBindings())->toBe(['1', '2']);
})->with([
    'in' => ['in', 'order_total in (?, ?)'],
    'not in' => ['not_in', 'order_total not in (?, ?)'],
    'between' => ['between', 'order_total between ? and ?'],
    'not between' => ['not_between', 'order_total not between ? and ?'],
]);

it('converts date values to carbon instances', function(): void {
    $query = parseQbpRules([
        makeQbpRule('order_date', 'between', ['2025/01/01', '2025/01/31'], 'date'),
        makeQbpRule('date_added', 'greater', '2025/02/01', 'date'),
    ]);

    [$start, $end, $after] = $query->getBindings();

    expect($start)->toBeInstanceOf(Carbon::class)
        ->and($start->toDateString())->toBe('2025-01-01')
        ->and($end->toDateString())->toBe('2025-01-31')
        ->and($after)->toBeString()->toStartWith('2025-02-01');
});

it('joins rules using the group condition', function(): void {
    $query = parseQbpRules([
        makeQbpRule('email', 'equal', 'a@example.com'),
        makeQbpRule('email', 'equal', 'b@example.com'),
    ], 'OR');

    expect(qbpWhereSql($query))->toBe('email = ? or email = ?');
});

it('builds nested groups', function(): void {
    $query = parseQbpRules([
        makeQbpRule('order_type', 'equal', 'delivery'),
        [
            'condition' => 'OR',
            'rules' => [
                makeQbpRule('email', 'equal', 'a@example.com'),
                [
                    'condition' => 'AND',
                    'rules' => [
                        makeQbpRule('order_total', 'greater', '10', 'double'),
                        makeQbpRule('order_total', 'less', '20', 'double'),
                    ],
                ],
            ],
        ],
    ]);

    expect(qbpWhereSql($query))->toBe('order_type = ? and (email = ? or (order_total > ? and order_total < ?))')
        ->and($query->getBindings())->toBe(['delivery', 'a@example.com', '10', '20']);
});

it('skips incomplete or unknown rules', function(array $rule): void {
    $query = parseQbpRules([$rule]);

    expect($query->getQuery()->wheres)->toBeEmpty();
})->with([
    'missing type' => [array_except(makeQbpRule('email', 'equal', 'test'), 'type')],
    'missing operator' => [array_except(makeQbpRule('email', 'equal', 'test'), 'operator')],
    'unknown operator' => [makeQbpRule('email', 'unknown', 'test')],
]);

it('allows only the configured fields', function(): void {
    expect(qbpWhereSql(parseQbpRules([makeQbpRule('email', 'equal', 'test')], fields: ['email'])))->toBe('email = ?')
        ->and(fn(): Builder => parseQbpRules([makeQbpRule('order_total', 'equal', '10')], fields: ['email']))
        ->toThrow(FlashException::class, sprintf(lang('igniterlabs.reports::default.alert_query_field_not_allowed'), 'order_total'));
});

it('throws exception for invalid input', function(array $rules, string $condition, string $message): void {
    expect(fn(): Builder => parseQbpRules($rules, $condition))->toThrow(FlashException::class, $message);
})->with([
    'invalid condition' => fn(): array => [
        [makeQbpRule('email', 'equal', 'test')], 'XOR', lang('igniterlabs.reports::default.alert_query_invalid_condition'),
    ],
    'invalid nested condition' => fn(): array => [
        [['condition' => 'XOR', 'rules' => [makeQbpRule('email', 'equal', 'test')]]], 'AND', lang('igniterlabs.reports::default.alert_query_invalid_condition'),
    ],
    'array operator without array' => fn(): array => [
        [makeQbpRule('order_type', 'in', 'delivery')], 'AND', sprintf(lang('igniterlabs.reports::default.alert_query_value_must_be_array'), 'order_type'),
    ],
    'multiple values for single value operator' => fn(): array => [
        [makeQbpRule('email', 'equal', ['a', 'b'])], 'AND', sprintf(lang('igniterlabs.reports::default.alert_query_value_must_not_be_array'), 'email'),
    ],
    'between without two values' => fn(): array => [
        [makeQbpRule('order_total', 'between', ['1'], 'double')], 'AND', sprintf(lang('igniterlabs.reports::default.alert_query_between_requires_two_values'), 'order_total'),
    ],
]);

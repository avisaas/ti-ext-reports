<?php

declare(strict_types=1);

namespace IgniterLabs\Reports\Classes;

use Igniter\Flame\Database\Builder;
use Igniter\Flame\Database\Query\Builder as QueryBuilder;
use Igniter\Flame\Exception\FlashException;
use Illuminate\Support\Carbon;

/**
 * Applies jQuery QueryBuilder rules onto a database query.
 *
 * Ported from ianvizarra/query-builder-parser (a fork of timgws/QueryBuilderParser).
 */
class QueryBuilderParser
{
    /**
     * QueryBuilder operators and whether they accept a value.
     */
    protected const array OPERATORS = [
        'equal' => ['accept_values' => true, 'sql' => '='],
        'not_equal' => ['accept_values' => true, 'sql' => '!='],
        'in' => ['accept_values' => true, 'sql' => 'IN'],
        'not_in' => ['accept_values' => true, 'sql' => 'NOT IN'],
        'less' => ['accept_values' => true, 'sql' => '<'],
        'less_or_equal' => ['accept_values' => true, 'sql' => '<='],
        'greater' => ['accept_values' => true, 'sql' => '>'],
        'greater_or_equal' => ['accept_values' => true, 'sql' => '>='],
        'between' => ['accept_values' => true, 'sql' => 'BETWEEN'],
        'not_between' => ['accept_values' => true, 'sql' => 'NOT BETWEEN'],
        'begins_with' => ['accept_values' => true, 'sql' => 'LIKE', 'suffix' => '%'],
        'not_begins_with' => ['accept_values' => true, 'sql' => 'NOT LIKE', 'suffix' => '%'],
        'contains' => ['accept_values' => true, 'sql' => 'LIKE', 'prefix' => '%', 'suffix' => '%'],
        'not_contains' => ['accept_values' => true, 'sql' => 'NOT LIKE', 'prefix' => '%', 'suffix' => '%'],
        'ends_with' => ['accept_values' => true, 'sql' => 'LIKE', 'prefix' => '%'],
        'not_ends_with' => ['accept_values' => true, 'sql' => 'NOT LIKE', 'prefix' => '%'],
        'is_empty' => ['accept_values' => false, 'sql' => '='],
        'is_not_empty' => ['accept_values' => false, 'sql' => '!='],
        'is_null' => ['accept_values' => false, 'sql' => 'NULL'],
        'is_not_null' => ['accept_values' => false, 'sql' => 'NOT NULL'],
    ];

    /**
     * SQL operators that require an array value.
     */
    protected const array ARRAY_OPERATORS = ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'];

    /**
     * @param null|array $fields Fields allowed to be filtered on, null allows any field.
     */
    public function __construct(protected ?array $fields = null) {}

    /**
     * Apply the given QueryBuilder rules (decoded getRules() output) onto the query.
     */
    public function parse(array $ruleData, Builder|QueryBuilder $query): Builder|QueryBuilder
    {
        $rules = $ruleData['rules'] ?? null;
        if (!is_array($rules) || $rules === []) {
            return $query;
        }

        return $this->loopThroughRules($rules, $query, $ruleData['condition'] ?? 'AND');
    }

    protected function loopThroughRules(array $rules, Builder|QueryBuilder $query, string $condition): Builder|QueryBuilder
    {
        foreach ($rules as $rule) {
            // Group rules are not valid rules on their own, so makeQuery leaves the query untouched.
            $query = $this->makeQuery($query, $rule, $condition);

            if ($this->isNested($rule)) {
                $query = $this->createNestedQuery($query, $rule, $condition);
            }
        }

        return $query;
    }

    protected function isNested(array $rule): bool
    {
        return isset($rule['rules']) && is_array($rule['rules']) && $rule['rules'] !== [];
    }

    /**
     * Wrap a group of rules in a nested where, joined to the parent query using the parent's condition.
     */
    protected function createNestedQuery(Builder|QueryBuilder $query, array $rule, string $condition): Builder|QueryBuilder
    {
        $condition = $this->validateCondition($condition);
        $groupCondition = $rule['condition'] ?? 'AND';

        return $query->whereNested(function($nestedQuery) use ($rule, $groupCondition): void {
            foreach ($rule['rules'] as $loopRule) {
                $this->isNested($loopRule)
                    ? $this->createNestedQuery($nestedQuery, $loopRule, $groupCondition)
                    : $this->makeQuery($nestedQuery, $loopRule, $groupCondition);
            }
        }, $condition);
    }

    protected function makeQuery(Builder|QueryBuilder $query, array $rule, string $condition): Builder|QueryBuilder
    {
        $condition = $this->validateCondition($condition);

        if (!$this->isRuleValid($rule)) {
            return $query;
        }

        $this->ensureFieldIsAllowed($rule['field']);

        $sqlOperator = static::OPERATORS[$rule['operator']]['sql'];
        $value = $this->getRuleValue($rule);

        if ($this->operatorRequiresArray($sqlOperator)) {
            return $this->makeArrayQuery($query, $rule['field'], $sqlOperator, $value, $condition);
        }

        return match ($sqlOperator) {
            'NULL' => $query->whereNull($rule['field'], $condition),
            'NOT NULL' => $query->whereNotNull($rule['field'], $condition),
            default => $query->where($rule['field'], $sqlOperator, $value, $condition),
        };
    }

    /**
     * Ensure the rule has the field, operator and type set and uses a known operator.
     */
    protected function isRuleValid(array $rule): bool
    {
        if (!isset($rule['operator'], $rule['id'], $rule['field'], $rule['type'])) {
            return false;
        }

        return array_key_exists($rule['operator'], static::OPERATORS);
    }

    protected function getRuleValue(array $rule): mixed
    {
        $operator = static::OPERATORS[$rule['operator']];

        if ($operator['accept_values'] === false) {
            return in_array($rule['operator'], ['is_empty', 'is_not_empty']) ? '' : null;
        }

        $requireArray = $this->operatorRequiresArray($operator['sql']);
        $value = $this->enforceArrayOrString($requireArray, $rule['value'] ?? null, $rule['field']);

        // Turn dates into Carbon instances so they work with "between" operators etc.
        if ($rule['type'] === 'date') {
            $value = is_array($value)
                ? array_map(fn($date): Carbon => Carbon::parse($date), $value)
                : Carbon::parse($value);
        }

        if (!$requireArray) {
            $value = ($operator['prefix'] ?? '').$value.($operator['suffix'] ?? '');
        }

        return $value;
    }

    protected function makeArrayQuery(Builder|QueryBuilder $query, string $field, string $sqlOperator, array $value, string $condition): Builder|QueryBuilder
    {
        if (in_array($sqlOperator, ['BETWEEN', 'NOT BETWEEN'], true) && count($value) !== 2) {
            throw FlashException::error(sprintf(lang('igniterlabs.reports::default.alert_query_between_requires_two_values'), $field));
        }

        return match ($sqlOperator) {
            'IN' => $query->whereIn($field, $value, $condition),
            'NOT IN' => $query->whereNotIn($field, $value, $condition),
            'NOT BETWEEN' => $query->whereNotBetween($field, $value, $condition),
            default => $query->whereBetween($field, $value, $condition),
        };
    }

    protected function operatorRequiresArray(string $sqlOperator): bool
    {
        return in_array($sqlOperator, static::ARRAY_OPERATORS);
    }

    /**
     * Make sure that a condition is either 'and' or 'or'.
     */
    protected function validateCondition(string $condition): string
    {
        $condition = strtolower(trim($condition));

        throw_unless(in_array($condition, ['and', 'or'], true),
            FlashException::error(lang('igniterlabs.reports::default.alert_query_invalid_condition'))
        );

        return $condition;
    }

    /**
     * Enforce an array value for array operators, and flatten single item arrays otherwise.
     */
    protected function enforceArrayOrString(bool $requireArray, mixed $value, string $field): mixed
    {
        throw_if($requireArray && !is_array($value),
            FlashException::error(sprintf(lang('igniterlabs.reports::default.alert_query_value_must_be_array'), $field))
        );

        if (!$requireArray && is_array($value)) {
            throw_if(count($value) !== 1,
                FlashException::error(sprintf(lang('igniterlabs.reports::default.alert_query_value_must_not_be_array'), $field))
            );

            return $value[0];
        }

        return $value;
    }

    protected function ensureFieldIsAllowed(string $field): void
    {
        throw_if(is_array($this->fields) && !in_array($field, $this->fields),
            FlashException::error(sprintf(lang('igniterlabs.reports::default.alert_query_field_not_allowed'), $field))
        );
    }
}

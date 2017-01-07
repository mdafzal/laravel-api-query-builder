<?php

namespace Unlu\Laravel\Api;

use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator as BasePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unlu\Laravel\Api\Exceptions\UnknownColumnException;
use Unlu\Laravel\Api\UriParser;

class QueryBuilder
{
    protected $model;

    protected $uriParser;

    protected $wheres = [];

    protected $orderBy = [];

    protected $limit;

    protected $page = 1;

    protected $offset = 0;

    protected $columns = ['*'];

    protected $relationColumns = [];

    protected $includes = [];

    protected $groupBy = [];

    protected $excludedParameters = [];

    protected $appends = [];

    protected $query;

    protected $result;

    public function __construct(Model $model, Request $request)
    {
        $this->orderBy = config('api-query-builder.orderBy');

        $this->limit = config('api-query-builder.limit');

        $this->excludedParameters = array_merge($this->excludedParameters, config('api-query-builder.excludedParameters'));

        $this->model = $model;

        $this->uriParser = new UriParser($request);

        $this->query = $this->model->newQuery();
    }

    public function build()
    {
        $this->prepare();

        if ($this->hasWheres()) {
            array_map([$this, 'addWhereToQuery'], $this->wheres);
        }

        if ($this->hasGroupBy()) {
            $this->query->groupBy($this->groupBy);
        }

        if ($this->hasLimit()) {
            $this->query->take($this->limit);
        }

        if ($this->hasOffset()) {
            $this->query->skip($this->offset);
        }

        array_map([$this, 'addOrderByToQuery'], $this->orderBy);

        $this->query->with($this->includes);

        $this->query->select($this->columns);

        return $this;
    }

    public function get()
    {
        $result = $this->query->get();

        if ($this->hasAppends()) {
            $result = $this->addAppendsToModel($result);
        }

        return $result;
    }

    public function paginate()
    {
        if (!$this->hasLimit()) {
            throw new Exception("You can't use unlimited option for pagination", 1);
        }

        $result = $this->basePaginate($this->limit);

        if ($this->hasAppends()) {
            $result = $this->addAppendsToModel($result);
        }

        return $result;
    }

    public function lists($value, $key)
    {
        return $this->query->lists($value, $key);
    }

    protected function prepare()
    {
        $this->setWheres($this->uriParser->whereParameters());

        $constantParameters = $this->uriParser->constantParameters();

        array_map([$this, 'prepareConstant'], $constantParameters);

        if ($this->hasIncludes() && $this->hasRelationColumns()) {
            $this->fixRelationColumns();
        }

        return $this;
    }

    protected function prepareConstant($parameter)
    {
        if (!$this->uriParser->hasQueryParameter($parameter)) {
            return;
        }

        $callback = [$this, $this->setterMethodName($parameter)];

        $callbackParameter = $this->uriParser->queryParameter($parameter);

        call_user_func($callback, $callbackParameter['value']);
    }

    protected function setIncludes($includes)
    {
        $this->includes = array_filter(explode(',', $includes));
    }

    protected function setPage($page)
    {
        $this->page = (int)$page;

        $this->offset = ($page - 1) * $this->limit;
    }

    protected function setColumns($columns)
    {
        $columns = array_filter(explode(',', $columns));

        $this->columns = $this->relationColumns = [];

        array_map([$this, 'setColumn'], $columns);
    }

    protected function setColumn($column)
    {
        if ($this->isRelationColumn($column)) {
            return $this->appendRelationColumn($column);
        }

        $this->columns[] = $column;
    }

    protected function appendRelationColumn($keyAndColumn)
    {
        list($key, $column) = explode('.', $keyAndColumn);

        $this->relationColumns[$key][] = $column;
    }

    protected function fixRelationColumns()
    {
        $keys = array_keys($this->relationColumns);

        $callback = [$this, 'fixRelationColumn'];

        array_map($callback, $keys, $this->relationColumns);
    }

    protected function fixRelationColumn($key, $columns)
    {
        $index = array_search($key, $this->includes);

        unset($this->includes[$index]);

        $this->includes[$key] = $this->closureRelationColumns($columns);
    }

    protected function closureRelationColumns($columns)
    {
        return function ($q) use ($columns) {
            $q->select($columns);
        };
    }

    protected function setOrderBy($order) 
    {
        $this->orderBy = [];

        $orders = array_filter(explode('|', $order));

        array_map([$this, 'appendOrderBy'], $orders);
    }

    protected function appendOrderBy($order)
    {
        if ($order == 'random') {
            $this->orderBy[] = 'random';
            return;
        }

        list($column, $direction) = explode(',', $order);

        $this->orderBy[] = [
            'column' => $column,
            'direction' => $direction
        ];
    }

    protected function setGroupBy($groups)
    {
        $this->groupBy = array_filter(explode(',', $groups));
    }

    protected function setLimit($limit) 
    {
        $limit = ($limit == 'unlimited') ? null : (int)$limit;

        $this->limit = $limit;
    }

    protected function setWheres($parameters) 
    {
        $this->wheres = $parameters;
    }

    protected function setAppends($appends)
    {
        $this->appends = explode(',', $appends);
    }

    protected function addWhereToQuery($where)
    {
        extract($where);

        // For array values (whereIn, whereNotIn)
        if (isset($values)) {
            $value = $values;
        }
        if (!isset($operator)) {
            $operator = '';
        }

        /** @var mixed $key */
        if ($this->isExcludedParameter($key)) {
            return;
        }

        if ($this->hasCustomFilter($key)) {
            /** @var string $type */
            return $this->applyCustomFilter($key, $operator, $value, $type);
        }

        if (!$this->hasTableColumn($key)) {
            throw new UnknownColumnException("Unknown column '{$key}'");
        }

        /** @var string $type */
        if ($type == 'In') {
            $this->query->whereIn($key, $value);
        } else if ($type == 'NotIn') {
            $this->query->whereNotIn($key, $value);
        } else {
            if ($value == '[null]') {
                if ($operator == '=') {
                    $this->query->whereNull($key);
                } else {
                    $this->query->whereNotNull($key);
                }
            } else {
                $this->query->where($key, $operator, $value);
            }
        }
    }

    protected function addOrderByToQuery($order)
    {
        if ($order == 'random') {
            return $this->query->orderBy(DB::raw('RAND()'));
        }

        extract($order);

        /** @var string $column */
        /** @var string $direction */
        $this->query->orderBy($column, $direction);
    }

    protected function applyCustomFilter($key, $operator, $value, $type = 'Basic')
    {
        $callback = [$this, $this->customFilterName($key)];

        $this->query = call_user_func($callback, $this->query, $value, $operator, $type);
    }

    protected function isRelationColumn($column)
    {
        return (count(explode('.', $column)) > 1);
    }

    protected function isExcludedParameter($key)
    {
        return in_array($key, $this->excludedParameters);
    }

    protected function hasWheres() 
    {
        return (count($this->wheres) > 0);
    }

    protected function hasIncludes()
    {
        return (count($this->includes) > 0);
    }

    protected function hasAppends()
    {
        return (count($this->appends) > 0);
    }

    protected function hasGroupBy()
    {
        return (count($this->groupBy) > 0);
    }

    protected function hasLimit()
    {
        return ($this->limit);
    }

    protected function hasOffset()
    {
        return ($this->offset != 0);
    }

    protected function hasRelationColumns()
    {
        return (count($this->relationColumns) > 0);
    }

    protected function hasTableColumn($column)
    {
        return (Schema::hasColumn($this->model->getTable(), $column));
    }

    protected function hasCustomFilter($key)
    {
        $methodName = $this->customFilterName($key);

        return (method_exists($this, $methodName));
    }

    protected function setterMethodName($key)
    {
        return 'set' . studly_case($key);
    }

    protected function customFilterName($key)
    {
        return 'filterBy' . studly_case($key);
    }

    protected function addAppendsToModel($result)
    {
        $result->map(function ($item) {
            $item->append($this->appends);
            return $item;
        });

        return $result;
    }

    /**
     * Paginate the given query.
     *
     * @param  int $perPage
     * @param  array $columns
     * @param  string $pageName
     * @param  int|null $page
     * @return Paginator
     *
     * @throws \InvalidArgumentException
     */
    protected function basePaginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = $page ?: BasePaginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        if (method_exists($this->query, 'toBase')) {
            $query = $this->query->toBase();
        } else {
            $query = $this->query->getQuery();
        }

        $total = $query->getCountForPagination();

        $results = $total ? $this->query->forPage($page, $perPage)->get($columns) : new Collection;

        return (new Paginator($results, $total, $perPage, $page, [
            'path' => BasePaginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]))->setQueryUri($this->uriParser->getQueryUri());
    }
}

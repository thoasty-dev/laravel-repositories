<?php

namespace thoasty\LaravelRepositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RepositoryBuilder {
    protected $repository;

    protected $context;
    protected $attributes = [];
    protected $relations = [];

    protected $relation = null;

    protected $where = [];
    protected $orders = [];

    public function __construct(Repository $repository, Context $context)
    {
        $this->repository = $repository;
        $this->context = $context;
    }

    public function forRequest (Request $request) {
        $this->withAttributes($request->get('attributes', []));
        $this->withRelations($request->get('relations', []));

        $this->orders = [];
        if ($orders = $request->get('order')) {
            if (is_array($orders)) {
                if (is_array($orders[0])) {
                    foreach($orders as $order) {
                        $this->orderBy($order[0], $order[1]);
                    }
                }
                else {
                    $this->orderBy($orders[0], $orders[1]);
                }
            }
            else {
                $this->orderBy($orders);
            }
        }

        return $this;
    }

    public function forRelationRequest (Request $request, $parent_id, $relation) {
        $this->withAttributes(['id']);
        $builder = $this->getRelationBuilder($parent_id, $relation);

        return $builder->forRequest($request);
    }

    public function getRelationBuilder ($parent_id, $relation) {
        $repository = $this->repository;
        $context = $this->context;

        $parent = $this->getSingle($parent_id);

        if (!$parent) {
            throw new ModelNotFoundException('The parent model was not found.');
        }

        $relations = $repository->relations();

        $context = new Context($context->user, $parent);

        $relation_repository_class = array_get($relations, $relation);

        if (!$relation_repository_class) {
            throw new ModelNotFoundException('The relation model was not found.');
        }

        $relation_repository = new $relation_repository_class;

        $relation_context = new Context($context->user, $parent);

        return new static($relation_repository, $relation_context);
    }

    public function getAll () {
        $context = $this->context;
        $repository = $this->repository;

        $model_class = $repository->class();
        /** @var Model $model_instance */
        $model_instance = new $model_class;

        $collection_filters = [];
        $collection_callbacks = [];

        $repository_attributes = $repository->attributes();
        $repository_relations = $repository->relations($context);

        $attributes = [];
        $relations = [];

        $attributes_relation = [];

        /*
         * Prepare attributes as relations
         */
        foreach ($this->attributes as $attribute) {
            if (in_array($attribute, $repository_attributes)) {
                $attributes[] = $attribute;
            }
            elseif (strpos($attribute, '.') !== false) {
                $attribute_relation = explode('.', $attribute);

                $attributes_relation[$attribute_relation[0]][] = $attribute_relation[1];
            }
        }

        foreach ($this->relations as $relation) {
            if (isset($repository_relations[$relation])) {
                $relations[] = $relation;
            }
        }

        /*
         * Start query
         */
        $query = $repository->query($context);

        /*
         * Use constraints
         */
        foreach ($this->where as $where) {
            $where[0] = $model_instance->getTable() . '.' . $where[0];
            $query->where(...$where);
        }

        /*
         * Hide every attribute on the class
         */
        $class = $repository->class();

        $class::setStaticVisible(['']); // This is a hack to hide everything
        $class::setStaticHidden(['']);

        /*
         * We check if the repository has a "query with $attribute" method to add a specific attribute right
         * onto the database query
         */
        foreach ($attributes as $attribute) {
            if ($query_with_attribute_method = $this->repositoryMethod('query_with_attribute_' . $attribute)) {
                $repository->$query_with_attribute_method($context, $query);
            }
        }

        foreach ($relations as $relation) {
            /** @var Repository $repository_relation */
            $repository_relation = new $repository_relations[$relation]();

            $relation_class = $repository_relation->class();

            $relation_context = new Context($context->getUser(), null);

            if ($repository_relation->authorizeAll($relation_context)) {
                $repository_relation->queryWith($relation_context, $query);

                $class::addStaticVisible($relation);

                if (isset($attributes_relation[$relation])) {
                    $repository_relation_attributes = $repository_relation->attributes();

                    foreach ($attributes_relation[$relation] as $attribute) {
                        if (in_array($attribute, $repository_relation_attributes)) {
                            // @@ToDo: we need to use authorize and record withAttribute methods
                            $relation_class::addStaticVisible($attribute);
                        }
                    }
                }
            }
        }

        foreach ($this->orders as $order) {
            $attribute = $order[0];
            $direction = $order[1];

            if ($query_order_by_attribute_method = $this->repositoryMethod('query_order_by_' . $attribute)) {
                $repository->$query_order_by_attribute_method($context, $query, $direction);
            }
        }

        foreach ($attributes as $attribute) {
            $record_with_attribute_method = $this->repositoryMethod('record_with_attribute_' . $attribute);

            if ($this->authorizeAllAttribute($attribute)) {
                $class::addStaticVisible($attribute);

                if ($record_with_attribute_method) {
                    $collection_callbacks[] = function ($record) use ($repository, $record_with_attribute_method, $context, $attribute) {
                        if ($record_with_attribute_method) {
                            $repository->$record_with_attribute_method($context, $record);
                        }
                    };
                }
            }
            else {
                /** @var Model $record */
                $collection_callbacks[] = function ($record) use ($repository, $record_with_attribute_method, $context, $attribute) {
                    if ($this->authorizeSingleAttribute($attribute, $record)) {
                        $record->makeVisible($attribute);

                        if ($record_with_attribute_method) {
                            $repository->$record_with_attribute_method($context, $record);
                        }
                    }
                };
            }
        }

        /*
         * Get the results of our query
         */
        $collection = $query->get();

        /*
         * Filter the results with the filters we have collected
         */
        foreach($collection_filters as $collection_filter) {
            $collection = $collection->filter($collection_filter);
        }

        /*
         * Run the callbacks we have collected on every record
         */
        foreach ($collection_callbacks as $collection_callback) {
            $collection = $collection->each(function ($record) use ($collection_callback, $context) {
                $collection_callback($record, $context);
            });
        }

        return $collection;
    }

    public function getSingle ($id) {
        $this->where('id', $id);

        $all = $this->getAll();

        if (count($all) !== 1) {
            return null;
        }

        return $all[0];
    }

    public function getQuery () {
        $query = $this->repository->query($this->context);

        return $query;
    }

    /**
     * @param string[] $attributes
     * @return $this
     */
    public function withAttributes (array $attributes) {
        $this->attributes = $attributes;

        return $this;
    }

    protected function authorizeAllAttribute ($attribute) {
        if ($method = $this->repositoryMethod('authorize_all_attribute_' . $attribute)) {
            return $this->repository->$method($this->context);
        }

        return true;
    }

    protected function authorizeSingleAttribute ($attribute, Model $record) {
        if ($method = $this->repositoryMethod('authorize_single_attribute_' . $attribute)) {
            return $this->repository->$method($this->context, $record);
        }

        return true;
    }

    public function withRelations ($relations) {
        $this->relations = $relations;
    }

    public function orderBy ($attribute, $direction = 'ASC') {
        $this->orders[] = [$attribute, $direction];

        return $this;
    }

    public function where ($column, $operator = null, $value = null, $boolean = 'and') {
        $this->where[] = func_get_args();
    }

    protected function repositoryMethod ($method) {
        $name = Str::camel($method);

        if (method_exists($this->repository, $name)) {
            return $name;
        }

        return null;
    }

    protected function parseRelationAttribute ($attribute) {
        explode('.', $attribute);
    }
}

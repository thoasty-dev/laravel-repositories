<?php

namespace thoasty\LaravelRepositories;

use Illuminate\Database\Eloquent\ModelNotFoundException;

abstract class RepositoryRelation extends Repository {
    public abstract function relationQuery();

    /**
     * @return array
     */
    public function attributes () {
        return [];
    }

    /**
     * @return boolean
     */
    public function authorizeAll (Context $context) {
        return false;
    }

    /**
     * @param $model
     * @return false
     */
    public function authorizeSingle (Context $context, $model) {
        return false;
    }

    /**
     * @param Context $context
     * @return array
     */
    public function relations () {
        return [];
    }

    public function getRelation ($id, $relationship_name) {
        $parent = $this->getSingle($id);

        if (!$parent) {
            throw new ModelNotFoundException();
        }

        $context = new Context($this->context->getUser(), $parent);

        $relationship_repository = array_get($this->relationships($context), $relationship_name);

        if (!$relationship_repository) {
            throw new ModelNotFoundException();
        }

        return new $relationship_repository($context);
    }
}

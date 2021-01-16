<?php

namespace thoasty\LaravelRepositories;

use Illuminate\Database\Eloquent\Builder;

abstract class Repository {
    /**
     * @return string
     */
    public abstract function class ();

    /**
     * @return Builder
     */
    public abstract function query (Context $context);

    /**
     * @param Context $context
     * @param Builder $query
     * @return null
     */
    public function queryWith (Context $context, $query) {
        return null;
    }

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
}

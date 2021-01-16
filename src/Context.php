<?php

namespace thoasty\LaravelRepositories;

use \Illuminate\Foundation\Auth\User;
use \Illuminate\Database\Eloquent\Model;

class Context {
    /** @var User */
    public $user = null;

    /** @var Model */
    public $parent = null;

    public function __construct (User $user = null, Model $parent = null) {
        $this->user = $user;
        $this->parent = $parent;
    }

    public function setUser (User $user = null) {
        $this->user = $user;
    }

    public function getUser () {
        return $this->user;
    }

    public function setParent (Model $model = null) {
        $this->parent = $model;
    }

    public function getParent () {
        return $this->parent;
    }
}

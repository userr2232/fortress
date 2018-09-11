<?php namespace NickAguilarH\Fortress\Traits;

/**
 * This file is part of Fortress,
 * a role & permission management solution for Laravel.
 *
 * @license MIT
 * @package NickAguilarH\Fortress
 */

use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;

trait FortressRoleTrait
{
    //Big block of caching functionality.
    public function cachedPermissions()
    {
        $rolePrimaryKey = $this->primaryKey;
        $cacheKey = 'fortress_permissions_for_role_' . $this->$rolePrimaryKey;
        if (Cache::getStore() instanceof TaggableStore) {
            return Cache::tags(config('fortress.permission_role_table'))->remember($cacheKey, config('cache.ttl', 60), function () {
                return $this->perms()->get();
            });
        } else return $this->perms()->get();
    }

    public function save(array $options = [])
    {   //both inserts and updates
        if (!parent::save($options)) {
            return false;
        }
        if (Cache::getStore() instanceof TaggableStore) {
            Cache::tags(config('fortress.permission_role_table'))->flush();
        }
        return true;
    }

    public function delete(array $options = [])
    {   //soft or hard
        if (!parent::delete($options)) {
            return false;
        }
        if (Cache::getStore() instanceof TaggableStore) {
            Cache::tags(config('fortress.permission_role_table'))->flush();
        }
        return true;
    }

    public function restore()
    {   //soft delete undo's
        if (!parent::restore()) {
            return false;
        }
        if (Cache::getStore() instanceof TaggableStore) {
            Cache::tags(config('fortress.permission_role_table'))->flush();
        }
        return true;
    }

    /**
     * Many-to-Many relations with the user model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(config('auth.providers.users.model'), config('fortress.role_user_table'), config('fortress.role_foreign_key'), config('fortress.user_foreign_key'));
    }

    /**
     * Many-to-Many relations with the permission model.
     * Named "perms" for backwards compatibility. Also because "perms" is short and sweet.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function perms()
    {
        return $this->belongsToMany(config('fortress.permission'), config('fortress.permission_role_table'), config('fortress.role_foreign_key'), config('fortress.permission_foreign_key'));
    }

    /**
     * Boot the role model
     * Attach event listener to remove the many-to-many records when trying to delete
     * Will NOT delete any records if the role model uses soft deletes.
     *
     * @return void|bool
     */
    public static function boot()
    {
        parent::boot();

        static::deleting(function ($role) {
            if (!method_exists(config('fortress.role'), 'bootSoftDeletes')) {
                $role->users()->sync([]);
                $role->perms()->sync([]);
            }

            return true;
        });
    }

    /**
     * Checks if the role has a permission by its name.
     *
     * @param string|array $name Permission name or array of permission names.
     * @param bool $requireAll All permissions in the array are required.
     *
     * @return bool
     */
    public function hasPermission($name, $requireAll = false)
    {
        if (is_array($name)) {
            foreach ($name as $permissionName) {
                $hasPermission = $this->hasPermission($permissionName);

                if ($hasPermission && !$requireAll) {
                    return true;
                } elseif (!$hasPermission && $requireAll) {
                    return false;
                }
            }

            // If we've made it this far and $requireAll is FALSE, then NONE of the permissions were found
            // If we've made it this far and $requireAll is TRUE, then ALL of the permissions were found.
            // Return the value of $requireAll;
            return $requireAll;
        } else {
            foreach ($this->cachedPermissions() as $permission) {
                if ($permission->name == $name) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Save the inputted permissions.
     *
     * @param mixed $inputPermissions
     *
     * @return void
     */
    public function savePermissions($inputPermissions)
    {
        if (!empty($inputPermissions)) {
            $this->perms()->sync($inputPermissions);
        } else {
            $this->perms()->detach();
        }

        if (Cache::getStore() instanceof TaggableStore) {
            Cache::tags(config('fortress.permission_role_table'))->flush();
        }
    }

    /**
     * Attach permission to current role.
     *
     * @param object|array $permission
     *
     * @return void
     */
    public function attachPermission($permission)
    {
        if (is_object($permission)) {
            $permission = $permission->getKey();
        }

        if (is_array($permission)) {
            return $this->attachPermissions($permission);
        }

        $this->perms()->attach($permission);
    }

    /**
     * Detach permission from current role.
     *
     * @param object|array $permission
     *
     * @return void
     */
    public function detachPermission($permission)
    {
        if (is_object($permission)) {
            $permission = $permission->getKey();
        }

        if (is_array($permission)) {
            return $this->detachPermissions($permission);
        }

        $this->perms()->detach($permission);
    }

    /**
     * Attach multiple permissions to current role.
     *
     * @param mixed $permissions
     *
     * @return void
     */
    public function attachPermissions($permissions)
    {
        foreach ($permissions as $permission) {
            $this->attachPermission($permission);
        }
    }

    /**
     * Detach multiple permissions from current role
     *
     * @param mixed $permissions
     *
     * @return void
     */
    public function detachPermissions($permissions = null)
    {
        if (!$permissions) $permissions = $this->perms()->get();

        foreach ($permissions as $permission) {
            $this->detachPermission($permission);
        }
    }
}

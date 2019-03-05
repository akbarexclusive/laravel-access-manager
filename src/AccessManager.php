<?php

namespace Drivezy\LaravelAccessManager;

use Drivezy\LaravelAccessManager\Models\PermissionAssignment;
use Drivezy\LaravelAccessManager\Models\RoleAssignment;
use Drivezy\LaravelUtility\LaravelUtility;
use Drivezy\LaravelUtility\Library\DateUtil;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Response;

/**
 * Class AccessManager
 * @package Drivezy\LaravelAccessManager
 */
class AccessManager {
    /**
     * @var string
     */
    private static $identifier = 'user-access-object-';
    /**
     * @var null
     */
    private static $userClass = null;

    /**
     * @param $role
     * @return bool
     */
    public static function hasRole ($role = null) {
        $userObject = self::getUserObject();

        //super user should always get access to all the resources in the system
        if ( in_array(1, $userObject->roles) || in_array('super-admin', $userObject->roleIdentifiers) ) return true;

        $roles = is_array($role) ? $role : [$role];

        //if the access is given to public for the same, allow the same
        if ( in_array(2, $roles) || in_array('public', $roles) ) return true;

        foreach ( $roles as $role ) {
            if ( is_numeric($role) ) {
                if ( in_array($role, $userObject->roles) ) return true;
            } elseif ( is_string($role) ) {
                if ( in_array($role, $userObject->roleIdentifiers) ) return true;
            }
        }

        return false;
    }

    /**
     * @param $role
     * @return bool
     */
    public static function hasAbsoluteRole ($role) {
        $userObject = self::getUserObject();

        //check if passed role is ids
        if ( is_numeric($role) ) {
            if ( in_array($role, $userObject->roles) ) return true;

            return false;
        }

        if ( in_array($role, $userObject->roleIdentifiers) ) return true;

        return false;
    }

    /**
     * @param $permission
     * @return bool
     */
    public static function hasPermission ($permission) {
        $userObject = self::getUserObject();

        //super user should always get access to all the resources in the system
        if ( in_array(1, $userObject->roles) ) return true;

        $permissions = is_array($permission) ? $permission : [$permission];

        foreach ( $permissions as $permission ) {
            if ( is_numeric($permission) ) {
                if ( in_array($permission, $userObject->permissions) ) return true;
            } elseif ( is_string($permission) ) {
                if ( in_array($permission, $userObject->permissionIdentifiers) ) return true;
            }
        }

        return false;
    }

    /**
     * @param $permission
     * @return bool
     */
    public static function hasAbsolutePermission ($permission) {
        $userObject = self::getUserObject();

        if ( is_numeric($permission) ) {
            if ( in_array($permission, $userObject->permissions) ) return true;

            return false;
        }

        if ( in_array($permission, $userObject->permissionIdentifiers) ) return true;

        return false;
    }

    /**
     * @param null $id
     * @return array|mixed
     */
    public static function getUserObject ($id = null) {
        $id = $id ? : Auth::id();
        $roles = $roleIdentifiers = $permissions = $permissionIdentifiers = [];

        //if no logged in user or no user passed
        if ( !$id )
            return (object) [
                'roles'                 => $roles,
                'roleIdentifiers'       => $roleIdentifiers,
                'permissions'           => $permissions,
                'permissionIdentifiers' => $permissionIdentifiers,
                'refreshed_time'        => DateUtil::getDateTime(),
            ];

        //see if the user object is present in the cache
        $object = Cache::get(self::$identifier . $id, false);
        if ( !$object )
            return self::setUserObject($id);

        //check if the user object is older than 30 mins
        if ( !isset($object->refreshed_time) )
            return self::setUserObject($id);

        if ( DateUtil::getDateTimeDifference($object->refreshed_time, DateUtil::getDateTime()) > 30 * 60 )
            return self::setUserObject($id);

        return $object;
    }

    /**
     * @param null $id
     * @return array
     */
    public static function setUserObject ($id = null) {
        $id = $id ? : Auth::id();
        $roles = $roleIdentifiers = $permissions = $permissionIdentifiers = [];

        if ( !$id ) return (object) [
            'roles'                 => $roles,
            'roleIdentifiers'       => $roleIdentifiers,
            'permissions'           => $permissions,
            'permissionIdentifiers' => $permissionIdentifiers,
            'refreshed_time'        => DateUtil::getDateTime(),
        ];

        $userClass = md5(LaravelUtility::getUserModelFullQualifiedName());

        //get the roles that are assigned to the user
        $records = RoleAssignment::with('role')->where('source_type', $userClass)->where('source_id', $id)->get();
        foreach ( $records as $record ) {
            if ( in_array($record->role_id, $roles) ) continue;

            array_push($roles, $record->role_id);
            array_push($roleIdentifiers, $record->role->identifier);
        }

        //get the permissions assigned to the user
        $records = PermissionAssignment::with('permission')->where('source_type', $userClass)->where('source_id', $id)->get();
        foreach ( $records as $record ) {
            if ( in_array($record->permission_id, $permissions) ) continue;

            array_push($permissions, $record->permission_id);
            array_push($permissionIdentifiers, $record->permission->identifier);
        }

        //create the access object against the user
        $accessObject = (object) [
            'roles'                 => $roles,
            'roleIdentifiers'       => $roleIdentifiers,
            'permissions'           => $permissions,
            'permissionIdentifiers' => $permissionIdentifiers,
            'refreshed_time'        => DateUtil::getDateTime(),
        ];

        Cache::forever(self::$identifier . $id, $accessObject);

        return $accessObject;
    }

    /**
     * @return mixed
     */
    public static function unauthorizedAccess () {
        return Response::json(['success' => false, 'response' => 'Insufficient Privileges'], 403);
    }

    /**
     * @return bool|\Illuminate\Contracts\Auth\Authenticatable|null
     */
    public static function getUserSessionDetails () {
        if ( !Auth::check() ) return false;

        $user = Auth::user();

        $user->access_object = AccessManager::setUserObject();
        $user->parent_user = ImpersonationManager::getImpersonatingUserSession();
        $user->access_token = AccessManager::generateTimeBasedUserToken($user);

        return $user;
    }


    /**
     * @param $token
     * @return bool
     */
    public static function verifyTimeBasedUserToken ($token) {
        try {
            $obj = explode(':', Crypt::decrypt($token));
            if ( $obj[2] - strtotime('now') > 0 ) {
                return $obj[0];
            }
        } catch ( DecryptException $e ) {
        }

        return false;
    }

    /**
     * @param $user
     * @return mixed
     */
    public static function generateTimeBasedUserToken ($user = null) {
        $user = $user ? : Auth::user();

        $string = $user->id . ':' . $user->email_id . ':' . strtotime("+1 day");

        return Crypt::encrypt($string);
    }

}

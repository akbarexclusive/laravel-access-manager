<?php

namespace Drivezy\LaravelAccessManager\Controllers;

use App\User;
use Drivezy\LaravelRecordManager\Controllers\RecordController;
use Drivezy\LaravelUtility\LaravelUtility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Class UserController
 * @package Drivezy\LaravelAccessManager\Controllers
 */
class UserController extends RecordController
{
    /**
     * @var string|null
     */
    protected $model = null;

    /**
     * UserController constructor.
     */
    public function __construct ()
    {
        $this->model = LaravelUtility::getUserModelFullQualifiedName();
        parent::__construct();
    }

    /**
     * @param Request $request
     * @return JsonResponse|mixed
     */
    public function store (Request $request)
    {
        //check for duplicate user
        $user = User::where('email', strtolower($request->email))->first();
        if ( $user )
            return failed_response('Email already in use');

        return parent::store($request);
    }

    /**
     * @param Request $request
     * @param $id
     * @return null
     */
    public function update (Request $request, $id)
    {
        //over-ridding the user password
        if ( $request->has('password') )
            $request->password = Hash::make($request->password);

        return parent::update($request, $id); // TODO: Change the autogenerated stub
    }
}

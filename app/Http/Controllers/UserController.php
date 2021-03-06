<?php

namespace App\Http\Controllers;

use App\Mail\PasswordReset;
use App\Models\City;
use App\Models\Class_;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $this->authorizeForUser(Auth::user(), 'viewAny', User::class);
        
        $selection = [
            'users.id as user_id',
            'users.name as user_name',
            'email', 'first_name',
            'last_name',
            'role_id',
            'roles.name as role_name',
        ];

        $searching = [
            'keyword' => $request->keyword,
            'columns' => [
                'users.name',
                'first_name',
                'last_name',
                'email',
            ],
        ];

        $filters = [
            ['column' => 'role_id', 'values' => json_decode($request->filter_role_id)],
        ];

        $description = [
            'user_number' => User::count(),
            'admin_number' => User::where('role_id', 1)->count(),
            'teacher_number' => User::where('role_id', 3)->count(),
            'student_number' => User::where('role_id', 2)->count(),
            'filter_roles' => Role::all(),
        ];

        $user_id = Auth::user()->id;
        $role_id = Auth::user()->role_id;
        $data = User::join('roles','users.role_id', 'roles.id');
            if ($role_id != 1)
                $data = $data->where('users.id', $user_id);
        $data = $this->indexDB($data, $selection, $searching, $filters);

        return response()->json(['dataSource' => $data->paginate(10), 'description' => $description]);
    }

    public function import(Request $request)
    {
        $this->authorizeForUser(Auth::user(), 'create', User::class);
        
        try {
            $cities = City::all();
            $roles = Role::all();
            $classes = Class_::join('majors', 'classes.major_id', 'majors.id')
                ->join('grades', 'classes.grade_id', 'grades.id')
                ->select('classes.id as id', 'majors.short_name as major_short_name', 'grades.name as grade_name')
                ->get();
            //Chuy???n csv sang m???ng
            $data = $this->csvToArray($request->import);

            foreach ($data as $key => $item) {
                //M?? h??a m???t kh???u
                $data[$key]['password'] = Hash::make($item['password']);

                //Chuy???n gi???i t??nh chu???i sang s???
                $data[$key]['gender'] = $item['gender'] == 'Nam' ? 0 : 1;

                //Chuy???n ng??y sinh s??? sang chu???i ng??y
                $data[$key]['dob'] = date('Y-m-d', ($item['dob'] - 25569) * 86400);

                //Chuy???n t??n th??nh ph??? t??? chu???i sang m??
                $data[$key]['city_id'] = $cities->filter(function ($city) use ($item) {
                     return $city->name ==  $item['city_id'];
                })->first()->id;

                //Chuy???n vai tr?? t??? chu???i sang m??
                $data[$key]['role_id'] = $roles->filter(function ($role) use ($item) {
                    return $role->name == $item['role_id'];
                })->first()->id;

                //Chuy???n class t??? t??n sang m??
                $data[$key]['class_id'] = $classes->filter(function ($class) use ($item) {
                    $major_short_name = substr($item['class_id'], -2, 2);
                    $grade_name = substr($item['class_id'], 1, strlen($item['class_id']) - 3);
                    return $class->major_short_name ==  $major_short_name && $class->grade_name == $grade_name;
                })->first()->id;
            }
            $info = $this->importDB('users', $data);
            if ($info)
                return response()->json(['type' => 'error', 'text' => $info]);
            else
                return response()->json(['type' => 'success', 'text' => 'Nh???p danh s??ch t??i kho???n th??nh c??ng']);
        } catch (Exception $e) {
            return response()->json(['type' => 'error', 'text' => $e->getMessage()]);
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    
    public function create()
    {
        $this->authorizeForUser(Auth::user(), 'create', User::class);
        
        //[id, name]
        $cities = City::query()->get();
        $classes = Class_::query()
        ->join('majors', 'classes.major_id', 'majors.id')
        ->join('grades', 'classes.grade_id', 'grades.id')
        ->select('classes.id as id', DB::raw('concat("K", grades.name, majors.short_name) as name'))
        ->get();
        $roles = Role::query()->get();
        $gender = [['id' => 0, 'name' => 'Nam' ], ['id' => 1, 'name' => 'N???' ]];
        return response()->json([
            'cities' => $cities,
            'roles' => $roles, 'classes' => $classes,
            'gender' => $gender
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $this->authorizeForUser(Auth::user(), 'create', User::class);

        try {
            //Ki???m tra s??? t???n t???i c???a t??i kho???n
            if (User::query()->where('name', $request->name)->count() > 0)
                return response()->json(['type' => 'error', 'text' => 'T??n t??i kho???n ???? t???n t???i']);

            //Ki???m tra m???t kh???u v?? nh???p l???i m???t kh???u tr??ng kh???p
            if (strcmp($request->confirm_pasword, $request->pasword) != 0)
                return response()->json(['type' => 'error', 'text' => 'M???t kh???u v?? nh???p l???i m???t kh???u kh??ng kh???p']);
            
            //Ki???m tra email ???? t???n t???i
            if (User::where('email', $request->email)->count() > 0)
                return response()->json(['type' => 'error', 'text' => 'T??n email ???? t???n t???i']);

            $this->insertDB('users', [
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'gender' => $request->gender,
                'dob' => $request->dob,
                'address' => $request->address,
                'phone' => $request->phone,
                'city_id' => $request->city_id,
                'role_id' => $request->role_id,
                'class_id' => $request->role_id == 2 ? $request->class_id : null,
                'avatar' => $request->avatar,
            ]);
        } catch(Exception $e) {
            return response()->json(['type' => 'error', 'text' => $e->getMessage()]);
        }
        return response()->json(['type' => 'success', 'text' => 'T???o t??i kho???n th??nh c??ng']);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $this->authorizeForUser(Auth::user(), 'view', [User::class, $id]);
        
        $data = User::find($id);
        if (empty($data)) {
            return response()->json(['type' => 'error', 'text' => 'Ng?????i d??ng kh??ng t???n t???i']);
        }
        else {
            $data = User::query()->join('roles','users.role_id', 'roles.id')
            ->join('cities','users.city_id', 'cities.id')
            ->join('countries','cities.country_id', 'countries.id')
            ->leftJoin('classes', 'users.class_id', 'classes.id')
            ->leftJoin('majors', 'classes.major_id', 'majors.id')
            ->leftJoin('grades', 'classes.grade_id', 'grades.id')
            ->select('users.id as user_id', 'users.name as user_name', 'email', 'first_name', 'last_name', DB::raw('concat(first_name, " ",last_name) as full_name'), 'gender', 'dob', 'address', 'phone', 'city_id', 'cities.name as city_name', 'countries.name as country_name', 'role_id', 'roles.name as role_name', 'classes.id as class_id', DB::raw('concat("K", grades.name, majors.short_name) as class_name'))
            ->find($id);
            return response()->json(['type' => 'success', 'text' => '', 'data' => $data, 'imageExists' => Storage::disk('public')->exists('images/users/' . $id . '.png')]);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $this->authorizeForUser(Auth::user(), 'update', User::class);

        $data = User::query()->find($id);
        return response()->json($data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser(Auth::user(), 'update', User::class);

        try {
            if ($request->password != $request->confirm_password)
                return response()->json(['type' => 'error', 'text' => 'M???t kh???u v?? nh???p l???i m???t kh???u kh??ng kh???p']);
            
            $data = [
                'email' => $request->email,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'gender' => $request->gender,
                'dob' => $request->dob,
                'address' => $request->address,
                'phone' => $request->phone,
                'city_id' => $request->city_id,
                'role_id' => $request->role_id,
                'class_id' => $request->role_id == 2 ? $request->class_id : null,
                'avatar' => $request->avatar,
            ];

            if ($request->password != '')
                $data['password'] = Hash::make($request->password);
            
            $this->updateDB('users', $id, $data);
        } catch(Exception $e) {
            return response()->json(['type' => 'error', 'text' => $e->getMessage()]);
        }
        return response()->json(['type' => 'success', 'text' => 'C???p nh???t t??i kho???n th??nh c??ng']);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        $this->authorizeForUser(Auth::user(), 'delete', User::class);

        if (in_array(1, $request->ids))
            return response()->json(['type' => 'error', 'text' => 'Kh??ng th??? x??a ???????c t??i kho???n admin']);
        
        try {
            $validate =  $this->deleteDB('users', $request->ids, $request->deleteRelation == 1 ? true : false);
            if ($validate === null)
                return response()->json(['type' => 'success', 'text' => 'X??a t??i kho???n th??nh c??ng']);
            else
                return response()->json(['type' => 'error', 'text' => 'X??a t??i kho???n kh??ng th??nh c??ng. ' . $validate]);
        } catch(Exception $e) {
            return response()->json(['type' => 'error', 'text' => $e->getMessage()]);
        }
    }

    public function checkLogin() {
            if (Auth::check())
                return response()->json(Auth::user());
            else
                return response()->json(false);
    }

    public function login(Request $request) {
        if (Auth::attempt(['name' => $request->user_name, 'password' => $request->password], true)) {
            return response()->json(['type' => 'success', 'text' => '???? ????ng nh???p', 'data' => Auth::user()]);
        }
        return response()->json(['type' => 'error', 'text' => 'T??i kho???n ho???c m???t kh???u kh??ng ????ng!']);
    }

    public function logout() {
        Auth::logout();
        return response()->json(['type' => 'success']);
    }

    public function sendCode(Request $request) {
        try {
            $dataSource = User::where('name', $request->email)->orWhere('email', $request->email)->first();
            if ($dataSource == null)
                return response()->json(['type' => 'error', 'text' => 'T??n t??i kho???n ho???c email kh??ng t???n t???i!']);
            $data = ['code' => rand(111111, 999999), 'name' => $dataSource->first_name . " " . $dataSource->last_name];
            $user = User::find($dataSource->id);
            $user->reset_code = $data['code'];
            $user->reset_code_at = Carbon::now();
            $user->save();
            Mail::to($dataSource->email)->send(new PasswordReset($data));
        
            if (Mail::failures()) {
                return response()->json(['type' => 'error', 'text' => 'Kh??ng g???i ???????c email! Vui l??ng th??? l???i.']);
            } else {
                return response()->json(['type' => 'success', 'text' => '???? g???i m?? x??c nh???n ?????n t??i kho???n.', 'data' => ['email' => $request->email]]);
            }
        } catch(Exception $e) {
            return response()->json(['type' => 'error', 'text' => $e->getMessage()]);
        }
    }

    public function resetPassword(Request $request) {
        try {
            $user = User::where('name', $request->email)->orWhere('email', $request->email)->first();
            if ($user == null)
                return response()->json(['type' => 'error', 'text' => 'T??n t??i kho???n ho???c email kh??ng t???n t???i!']);
            if ($user->reset_code == null)
                return response()->json(['type' => 'error', 'text' => 'Ch??a c?? m?? x??c nh???n, vui l??ng nh???p l???i t??n t??i kho???n ho???c email ????? g???i m?? x??c nh???n!']);
            if ($user->reset_code != $request->code)
                return response()->json(['type' => 'error', 'text' => 'M?? x??c nh???n kh??ng ????ng!']);
            
            $date = new Carbon($user->reset_code_at);
            $currentDate = Carbon::now();
            if ($date->diffInSeconds($currentDate) > 60*5)
                return response()->json(['type' => 'error', 'text' => 'M?? x??c nh???n ???? h???t h???n!']);
            if ($request->password != $request->comfirmed_password)
                return response()->json(['type' => 'error', 'text' => 'M???t kh???u kh??ng kh???p!']);
            
            $newUser = User::query()->find($user->id);
            $newUser->reset_code = null;
            $newUser->reset_code_at = null;
            $newUser->password = Hash::make($request->password);
            $newUser->save();
            return response()->json(['type' => 'success', 'text' => '?????t l???i m???t kh???u th??nh c??ng']);
        } catch(Exception $e) {
            return response()->json(['type' => 'error', 'text' => $e->getMessage()]);
        }
    }

    public function changeAvatar(Request $request, $id)
    {
        $this->authorizeForUser(Auth::user(), 'changeAvatar', [User::class, $id]);
        try {
            $this->updateDB('users', $id, [
                'avatar' => $request->avatar,
            ], false);
        } catch(Exception $e) {
            return response()->json(['type' => 'error', 'text' => $e->getMessage()]);
        }
        return response()->json(['type' => 'success', 'text' => '?????i avatar th??nh c??ng! m??? l???i khung ????? c???p nh???t ???nh hi???n th???.']);
    }
}

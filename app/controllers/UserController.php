<?php

class UserController extends BaseController {

    //Login
    public function authenticate(){

            $username = Input::get('email');
            $password = Input::get('password-login');      
            if(!isset($password))
            {
              $password =   Input::get('password');
            }
            elseif($password == '')
            {                
              $password =   Input::get('pwd');
            }  

        try
        {
            // Set login credentials
            $credentials = array(
                'email'    => $username,
                'password' => $password,
            );

            // Try to authenticate the user
            $user = Sentry::authenticate($credentials, false);            
        }
        catch (Cartalyst\Sentry\Users\LoginRequiredException $e)
        {
            Log::error('A User without Login tried to authenticate');   
            return View::make('account.login')->with('error',"Username is Required.");
        }
        catch (Cartalyst\Sentry\Users\PasswordRequiredException $e)
        {
            Log::error('User with Login '.$username.' Tried to access without password.');
            return View::make('account.login')->with('error',"Password is Required");
        }
        catch (Cartalyst\Sentry\Users\UserNotFoundException $e)
        {
            Log::error('User with Login '.$username.' Tried to access.But  Username was wrong');
            return View::make('account.login')->with('error',"Username or Password is wrong");
        }
        catch (Cartalyst\Sentry\Users\WrongPasswordException $e)
        {
            Log::error('User with Login '.$username.' Tried to access.The Entered password was Wrong.');            
            return View::make('account.login')->with('error',"Username or Password is wrong");
        }
        catch (Cartalyst\Sentry\Users\UserNotActivatedException $e)
        {
            Log::error('User with Login '.$username.' Tried to access.But the Account was not activated yet.');           
            return View::make('account.login')->with('error',"Account Not Activated");
        }

        // The following is only required if throttle is enabled
        catch (Cartalyst\Sentry\Throttling\UserSuspendedException $e)
        {
            Log::error('User with Login '.$username.' Tried to access.But the Account was Suspended.');
            return View::make('account.login')->with('error',"Suspended");
        }
        catch (Cartalyst\Sentry\Throttling\UserBannedException $e)
        {            
            Log::error('User with Login '.$username.' Tried to access.But the Account was Banned.');
            return View::make('account.login')->with('error',"Banned");
        }
        if (Sentry::check())
            {
                // User is logged in  
                Log::info('User with Login '.$username.' Logged In Successfully.');         
                return  Redirect::intended('/')->with('error','OK');
            }
    }
    public function logout(){     
        Sentry::logout();
        return Redirect::to('/');        
    }

    public function register(){        
        $captcha_type = Config::get('app.captcha');
        if($captcha_type=="captcha"){
            $captcha_field = "captcha";
            $captcha_validation = 'required|min:5|captcha';
        } 
        elseif($captcha_type == "recaptcha"){
            $captcha_field = "recaptcha_response_field";
            $captcha_validation = 'required|min:5|recaptcha';
        }
        elseif($captcha_type == "checkbox"){
            $captcha_field = "checkbox_captcha";
            $captcha_validation = "required|checkbox_captcha";
        }
        $validator = Validator::make(Input::all(),
                            array('fname'=>'required|min:3|alpha|different:lname',
                                'lname'=>'required|min:3|alpha|different:fname',
                                'email'=>'required|min:5|email|usercheck',
                                'password'=>'required|min:8|different:lname|different:fname|different:email|confirmed',
                                'actype'=>'required',
                                $captcha_field =>$captcha_validation));
        if ($validator->fails())
        {           
            
            Input::flash();
            return Redirect::to('register')->withErrors($validator);
        } 
        else
        {
                $email = Input::get('email');
                $password =   Input::get('password');
                $fname    = Input::get('fname');
                $lname    = Input::get('lname');
                $actype   = Input::get('actype');
                $subjects = Input::get('subjects');
                // Let's register a user.
                $user = Sentry::register(array(
                    'email'    => $email,
                    'password' => $password,
                    'first_name'=>$fname,
                    'last_name'=>$lname
                ));
                if($actype == 'students' || $actype == 'teachers'){
                $group = Sentry::getGroupProvider()->findByName($actype);

                $useract = \Sentry::getUserProvider()->findByLogin($email);
                if ($user->addGroup($group))
                {
                    // Group assigned successfully
                }
                else
                {
                    // Group was not assigned

                    //Log the Error of User Group set                
                    Log::error("assigning $useract to $group failed.");
                }}
                // Let's get the activation code
                $activationcode = $useract->getActivationCode();       
                $fname = Input::get('fname');
                $lname = Input::get('lname');

                if($actype == 'students'){
                    $student = new Student;
                    $student->user_id = $useract->id;
                    $student->email = $useract->email;
                    $student->extra = serialize($subjects);
                }
                if($actype == 'teachers'){
                    $teacher = new Teacher;
                    $teacher->user_id = $useract->id;
                    $teacher->email = $useract->email;
                    $teacher->extra = serialize($subjects);
                }
                $data = ['activation_code'=>$activationcode,
                    'fname'=> $fname,
                    'lname'=>$lname,
                    'email'=>$email,
                    'fullname'=>$fname.' '.$lname];


                Mail::send('emails.welcome',$data,function($message) use ($user)
                {
                    $usermail = DB::table('users')->where('email', $user->getLogin())->first();
                    $fullname = $usermail->first_name . ' '. $usermail->last_name;
                    $message->to($user->getLogin(),$fullname)->subject('Welcome! to EdLara');
                });
                return Redirect::to('/');
        }
    }



    public function showReg(){
        if (!Sentry::check())
        {
            return View::make('account.register')->nest('header','main.header');
        }
        else
        {
            // User is logged in   
            return Redirect::to('/');
        }
    }
    public function activateUser(){
        try
        {
            $login = $this->app('Input')->get('login');
            $activationcode = $this->app('Input')->get('code');
            // Find the user using the user id
            $user = \Sentry::getUserProvider()->findByLogin($login);

            // Attempt to activate the user
            if ($user->attemptActivation($activationcode))
            {
                // User activation passed
                return \View::make('account.login')->with('loginpass',1);
            }
            else
            {
                // User activation failed
                return \View::make('account.activation')->with('type','codemismatch');
            }
        }
        catch (Cartalyst\Sentry\Users\UserNotFoundException $e)
        {
            \Log::warning($login.' \'s account wasnt found in the system. Tried to activate the account.');            
            return \View::make('account.activationfail')->with('type','notfound');
        }
        catch (Cartalyst\SEntry\Users\UserAlreadyActivatedException $e)
        {
            \Log::warning($login.' \'s account was already activated');
            return \View::make('account.login')->with('error','alreadyactivated');
        }
    }
    public function acceptReset(){
        if (Input::has('email')){
                 $validator = Validator::make(Input::all(),
                                              array('email'=>'required|min:7|exists:users,email')
                                              );
                 if ($validator->fails())
                {           
            
                    Input::flash();
                    return Redirect::to('forgotpass')->withErrors($validator);
                } 
                $email = Input::get('email');
                // Find the user using the user email address
                $user = Sentry::getUserProvider()->findByLogin($email);

                // Get the password reset code
                $resetCode = $user->getResetPasswordCode();

                $fname = $user->first_name;
                $lname = $user->last_name;


                $data = ['reset_code'=>$resetCode,
                    'fname'=> $fname,
                    'lname'=>$lname,
                    'email'=>$email,
                    'fullname'=>$fname.' '.$lname];


                Mail::send('emails.resetpass',$data,function($message) use ($user)
                {
                    $usermail = DB::table('users')->where('email', $user->getLogin())->first();
                    $fullname = $usermail->first_name . ' '. $usermail->last_name;
                    $message->to($user->getLogin(),$fullname)->subject('EdLara - Reset Password');
                });
                Input::flash();
                return Redirect::to('account.acceptreset')->withInput();
        }
        return Redirect::to('forgotpass');
    }
    public function resetPass(){

            $email = Session::get('username',NULL);
            $resetcode = Session::get('key', NULL);

            $validator = Validator::make(Input::all(),
                                         array(
                                               'password'=>'required|min:8|max:20'
                                               )
                                         );
                if($validator->fails()){                    
                    return Redirect::to('account.acceptreset')->withErrors($validator);
                }
                else
                {
                $newpass = Input::get('password');
                // Find the user using the user id
                $user = Sentry::getUserProvider()->findByLogin($email);

                // Check if the reset password code is valid
                if ($user->checkResetPasswordCode($resetcode))
                {
                    // Attempt to reset the user password
                    if ($user->attemptResetPassword($resetcode,$newpass))
                    {
                        // Password reset passed
                        return  "Success";
                    }
                    else
                    {
                        // Password reset failed
                       return  "Fail";
                    }
                }
                else
                {
                    return  "CodeFail";
                    // The provided password reset code is Invalid
                }
            }
            return "Complete Fail";
        
    }
    public function manage($id,$mode){
        switch ($mode) {
            case 'view':
                return View::make('dashboard.user.view');
                break;
            case 'edit':
                return View::make('dashboard.user.edit');
                break;
            case 'delete':
                $user = Sentry::getUser();
                // Find the Administrator group
                $admin = Sentry::findGroupByName('admin');

                // Check if the user is in the administrator group
                if ($user->inGroup($admin))
                {
                return Redirect::to(URL::previous());
                }                
                else{
                    return "UNAUTHORISED ACTION";
                }
                break;
            case 'suspend':
                $user = Sentry::getUser();
                // Find the Administrator group
                $admin = Sentry::findGroupByName('admin');

                // Check if the user is in the administrator group
                if ($user->inGroup($admin))
                {
                $throttle = Sentry::findThrottlerByUserId($id);
                // Suspend the user
                $throttle->suspend();
                return Redirect::to(URL::previous());
                }
                else{
                    return "UNAUTHORISED ACTION";
                }
                break;
            case 'unsuspend':
                $throttle = Sentry::findThrottlerByUserId($id);
                // Suspend the user
                $throttle->unsuspend();
                return Redirect::to(URL::previous());
                break;
        }
    }
    public function showProfile($id){
        if('0' !== $id){
            if (!Sentry::check()){
                //User is not Logged In        
                $currentURL=URL::current();
                $currentURL = substr($currentURL, 8);
                $cutLength = strrpos($currentURL, '.');
                $cutLength = $cutLength + 4;
                $currentURL = substr($currentURL,$cutLength);
                Session::put('url.intended',$currentURL);
                return View::make('account.login',array('error'=>'OK'));
            }
            if(Sentry::getUser()->id == $id){
                return View::make('account.profile.edit')->nest('header','main.header')->with('id',$id);
            }
            else {
                return View::make('account.profile.view')->with('id',$id)->nest('header','main.header');
            }
        }
        else
        {
            if (!Sentry::check()){
                //User is not Logged In        
                $currentURL=URL::current();
                $currentURL = substr($currentURL, 8);
                $cutLength = strrpos($currentURL, '.');
                $cutLength = $cutLength + 4;
                $currentURL = substr($currentURL,$cutLength);
                Session::put('url.intended',$currentURL);
                return View::make('account.login',array('error'=>'OK'));
            }
            if(0 == $id){
                return View::make('account.profile.edit')->with('id',Sentry::getUser()->id)->nest('header','main.header');
            }
            else {
                return Redirect::to('profile/0');
            }
        }
    }

    public function editProfile(){
        return View::make('account.profile.edit');
    }

    public function updateProfile(){
        return View::make('account.profile.view');
    }
}

<?php

use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;
use SilverStripe\Assets\Upload;
use SilverStripe\Assets\Image;
use SilverStripe\Security\Security;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\IdentityStore;
use SilverStripe\View\ArrayData;

class AccountsPageController extends PageController
{
    private static $url_handlers = [
        'GET password/change' => 'passwordchange',
        'POST password/change' => 'changepassword',
        'POST password/forgot' => 'forgotpassword',
        'GET edit' => 'edit',
        'POST edit' => 'editprofile',
        'POST edit/picture' => 'editprofilepicture',
    ];

    private static $allowed_actions = [
        'edit',
        'passwordchange',
        'logout',
        'changepassword',
        'forgotpassword',
        'editprofile',
        'editprofilepicture'
    ];

    public function init()
    {
        parent::init();

        if (!Security::getCurrentUser()) return $this->redirect('login/');
    }

    public function index()
    {
        return $this->httpError(404);
    }

    public function edit()
    {
        // return $this->render([
        //     'ContentAccount' => $this->renderWith('Layout/Accounts/edit'),
        //     'active' => 'edit'
        // ]);
        return $this->customise(new ArrayData([
            'active' => 'edit'
        ]))->renderWith(['AccountsPage', 'Page']);
    }

    public function passwordchange()
    {
        // return $this->render([
        //     'ContentAccount' => $this->renderWith('Layout/Accounts/passwordchange'),
        //     'active' => 'passwordchange'
        // ]);
        return $this->customise(new ArrayData([
            'active' => 'password/change'
        ]))->renderWith(['AccountsPage', 'Page']);
    }

    public function logout()
    {
        $identityStore = Injector::inst()->get(IdentityStore::class);

        try {
            $identityStore->logOut();
            $this->getResponse()->setBody(json_encode([
                'status' => 200,
                'message' => 'Logout Success'
            ]));
            $this->getResponse()->setStatusCode(200, 'OK');
            $this->getResponse()->addHeader('content-type', 'application/json');
            return $this->getResponse();
        } catch (Exception $e) {
            $this->getResponse()->setBody(json_encode([
                'status' => 500,
                'message' => $e->getMessage()
            ]));
            $this->getResponse()->setStatusCode(500, 'Internal Server Error');
            $this->getResponse()->addHeader('content-type', 'application/json');
            return $this->getResponse();
        }
    }

    public function editprofilepicture()
    {
        if (!$this->getRequest()->isAjax()) return $this->httpError(404, 'NOT FOUND');

        // cek isset picture, tidak ada null
        $picture = $_POST['profilePicture'] ?? null;

        // jika null 
        $this->getResponse()->addHeader('content-type', 'application/json');
        if (is_null($picture)) return $this->getResponse()->setBody(json_encode([
            'status' => 400,
            'message' => 'Upload an image'
        ]));

        // ambil user yang sedang aktif
        $member = Security::getCurrentUser();

        // jika bukan null, coba upload file 
        try {
            $imageObject = Image::create();
            $upload = new Upload();
            $upload->loadIntoFile($picture, $imageObject, 'User Profile/' . $member->Username);
            // buat validasi 
            $upload->getValidator()->setAllowedExtensions(['jpg', 'jpeg', 'png']);
        } catch (Exception $e) {
            $this->getResponse()->setBody(json_encode([
                'status' => 500,
                'message' => $e->getMessage()
            ]));
            return $this->getResponse();
        }

        // jika berhasil upload file, coba ubah data 
        try {
            $member->PictureID = $imageObject->ID;
            $member->write();
        } catch (Exception $e) {
            $this->getResponse()->setBody(json_encode([
                'status' => 500,
                'message' => $e->getMessage()
            ]));
            return $this->getResponse();
        }

        $this->getResponse()->setBody(json_encode([
            'status' => 200,
            'message' => 'Your profile picture was successfully changed.',
            'url' => $imageObject->URL
        ]));
        return $this->getResponse();
    }

    public function editprofile()
    {
        if (!$this->getRequest()->isAjax()) return $this->httpError(404);
        // $this->getResponse()->addHeader('content-type', 'application/json');

        $profile = json_decode($this->getRequest()->getBody(), true);
        // $profile = $_POST;

        // cek apakah ada input yang kosong 
        if (!isset($profile['username'])) return $this->getResponse()->setBody(json_encode([
            'status' => 400,
            'message' => 'username cannot be empty!'
        ]));

        if (!isset($profile['email'])) return $this->getResponse()->setBody(json_encode([
            'status' => 400,
            'message' => 'email cannot be empty!'
        ]));

        $user = Security::getCurrentUser();
        // cek username
        $usernameExists = User::get()->filter([
            'Username' => $profile['username']
        ])->first();

        if (!is_null($usernameExists)) {
            if ($user->Username !== $usernameExists->Username) return $this->getResponse()->setBody(json_encode([
                'status' => 400,
                'message' => "This username isn't available. Please try another."
            ]));
        }

        // cek email
        $emailExists = User::get()->filter([
            'Email' => $profile['email']
        ])->first();

        if (!is_null($emailExists)) {
            if ($user->Email !== $emailExists->Email) return $this->getResponse()->setBody(json_encode([
                'status' => 400,
                'message' => "Another account is using " . $profile['email'] . "."
            ]));
        }

        // jika tidak ada username yang sama 
        $user->Username = $profile['username'];
        $user->Email = $profile['email'];
        $user->Bio = $profile['bio'];

        try {
            $user->write();
        } catch (ValidationException $e) {
            // jika gagal mengganti 
            return $this->getResponse()->setBody(json_encode([
                'status' => 400,
                'message' => $e->getMessage()
            ]));
        }

        return $this->getResponse()->setBody(json_encode([
            'status' => 200,
            'message' => 'Profile Saved'
        ]));
    }

    public function changepassword()
    {
        if (!$this->getRequest()->isAjax()) return $this->httpError(404);

        $userInput = json_decode($this->getRequest()->getBody(), true);
        // $userInput = $_POST;

        // * ===============================

        // ambil semua input
        // cek apakah old password sama dengan password user yang active
        $auth = new MemberAuthenticator;
        $userActive = Security::getCurrentUser();

        $result = $auth->checkPassword($userActive, $userInput['oldPassword']);

        $this->getResponse()->addHeader('content-type', 'application/json');

        // jika password tidak valid
        if (!$result->isValid()) return $this->getResponse()->setBody(json_encode([
            'status' => 400,
            'message' => "Your old password has been entered incorrectly. Please enter it again."
        ]));

        // jika password valid, cek apakah new password conf password sama  
        if ($userInput['newPassword'] !== $userInput['confPassword']) return $this->getResponse()->setBody(json_encode([
            'status' => 400,
            'message' => "Please make sure that both passwords match."
        ]));

        // jika sama, masukkan password baru untuk user 
        $userActive->Password = $userInput['newPassword'];
        try {
            $userActive->write();
        } catch (ValidationException $e) {
            return $this->getResponse()->setBody(json_encode([
                'status' => 500,
                'message' => $e->getMessage()
            ]));
        }

        return $this->getResponse()->setBody(json_encode([
            'status' => 200,
            'message' => 'Password changed.'
        ]));
    }
}

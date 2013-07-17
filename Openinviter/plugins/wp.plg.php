<?php
$_pluginInfo=array(
    'name'=>'Wirtualna Polska',
    'version'=>'1.0.2',
    'description'=>"Get the contacts from a Onet account",
    'base_version'=>'1.6.9',
    'type'=>'email',
    'check_url'=>'http://poczta.wp.pl',
    'requirement'=>'email',
    'allowed_domains'=>false,
    'imported_details'=>array('first_name','last_name','email_1','email_2','email_3'),
);
/**
 * O2 Plugin
 *
 * Imports user's contacts from O2's AddressBook
 *
 * @author OpenInviter
 * @version 1.0.0
 */
class wp extends openinviter_base
{
    private $login_ok=false;
    public $showContacts=true;
    public $internalError=false;
    protected $timeout=30;

    /**
     * Login function
     *
     * Makes all the necessary requests to authenticate
     * the current user to the server.
     *
     * @param string $user The current user.
     * @param string $pass The password for the current user.
     * @return bool TRUE if the current user was authenticated successfully, FALSE otherwise.
     */
    public function login($user,$pass)
    {
        $ch = curl_init();
        $cookie = $this->getCookiePath();
        curl_setopt($ch, CURLOPT_USERAGENT,(!empty($this->userAgent)?$this->userAgent:"Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.0.1) Gecko/2008070208 Firefox/3.0.1"));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_URL, 'http://poczta.wp.pl');
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        $loginSite = curl_exec($ch);

        $loginForm = $this->getElementString($loginSite, '<form ', '</form>');
        preg_match('|action="([\w:/\.\?_=&\d%;]+)"|', $loginForm, $matches);
        $formActionUrl = 'http://profil.wp.pl'.preg_replace('|&amp;|', '&', $matches[1]);

        preg_match('|src="([\w:/\.\?_=&\d;%]+)"|', $this->getElementString($loginSite, '<img', 'alt'), $matches);
        $webBug = 'http://profil.wp.pl/'.preg_replace('|&amp;|', '&', $matches[1]);

        curl_setopt($ch, CURLOPT_URL, $webBug);
        $webBugResponse = curl_exec($ch);

        $arrHiddenPost = $this->getHiddenElements($loginForm);
        $arrLoginPost = array('login_username'=>$user,'login_password'=>$pass);

        $arrPost = array_merge($arrHiddenPost, $arrLoginPost);

        curl_setopt($ch, CURLOPT_URL, $formActionUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($arrPost, '', '&'));
        $login = curl_exec($ch);

        curl_setopt($ch, CURLOPT_URL, 'http://kontakty.wp.pl/export.html');
        curl_setopt($ch, CURLOPT_POST, 0);
        $contactExportSite = curl_exec($ch);

        curl_setopt($ch, CURLOPT_URL, 'http://kontakty.wp.pl/export.html');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
                'action' => 'export_action',
                'mojeKontakty' => 'on',
                'exportClient' => 'oeex'
            ), '', '&')
        );
        curl_setopt($ch, CURLOPT_HEADER, 0);
        $contactsCsv = curl_exec($ch);

        $this->login_ok = $contactsCsv;
        return true;
    }

    /**
     * Get the current user's contacts
     *
     * Makes all the necesarry requests to import
     * the current user's contacts
     *
     * @return mixed The array if contacts if importing was successful, FALSE otherwise.
     */
    public function getMyContacts()
    {
        if (!$this->login_ok)
        {
            $this->debugRequest();
            $this->stopPlugin();
            return false;
        }
        $temp = $this->parseCSV($this->login_ok, ';');
        $contacts=array();
        foreach ($temp as $values)
        {
            if (!empty($values[5]))
            $contacts[$values[5]]=array('first_name'=>(!empty($values[0])?$values[0]:false),
                'middle_name'=>false,
                'last_name'=>(!empty($values[1])?$values[1]:false),
                'nickname'=>false,
                'email_1'=>(!empty($values[5])?$values[5]:false),
                'email_2'=>false,
                'email_3'=>false,
                'organization'=>false,
                'phone_mobile'=>false,
                'phone_home'=>false,
                'pager'=>false,
                'address_home'=>false,
                'address_city'=>false,
                'address_state'=>false,
                'address_country'=>false,
                'postcode_home'=>false,
                'company_work'=>false,
                'address_work'=>false,
                'address_work_city'=>false,
                'address_work_country'=>false,
                'address_work_state'=>false,
                'address_work_postcode'=>false,
                'fax_work'=>false,
                'phone_work'=>false,
                'website'=>false,
                'isq_messenger'=>false,
                'skype_essenger'=>false,
                'yahoo_essenger'=>false,
                'msn_messenger'=>false,
                'aol_messenger'=>false,
                'other_messenger'=>false,
            );
        }
        foreach ($contacts as $email=>$name)
            if (!$this->isEmail($email))
                unset($contacts[$email]);

        return $this->returnContacts($contacts);
    }

    /**
     * Terminate session
     *
     * Terminates the current user's session,
     * debugs the request and reset's the internal
     * debudder.
     *
     * @return bool TRUE if the session was terminated successfully, FALSE otherwise.
     */
    public function logout()
    {
        if (!$this->checkSession()) return false;
        if (file_exists($this->getLogoutPath()))
        {
            $url=file_get_contents($this->getLogoutPath());
            //go to url adress book  url in order to make the logout
            $res=$this->get($url,true);
            $form_action=$this->getElementString($res,'action="','"');
            $post_elements=$this->getHiddenElements($res);
            $post_elements['MSignal_AD-LGO*C-1.N-1']='Logout';

            //get the post elements and make de logout
            $res=$this->post($form_action,$post_elements,true);
        }
        $this->debugRequest();
        $this->resetDebugger();
        $this->stopPlugin();
        return true;
    }

    private function file_dump($content, $filename = false)
    {
        if (!$filename) {
            $file = 'D:\\dump.txt';
        } else {
            $file = 'D:\\'.$filename;
        }
        $data = array();
        if (is_string($content)) {
            $data[] = $content;
        } else {
            $data = $content;
        }
        $fh = fopen($file, 'w+');
        foreach ($data as $d) {
            fwrite($fh, $d);
        }
        fclose($fh);

        return true;
    }
}
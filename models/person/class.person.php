<?php

class person extends Model {

    /**
     * Required Fields
     * @var array
     */
    public $_required_fields = array(
        'fname'         => 'First Name',
        'lname'         => 'Last Name',
        'email_address' => 'Email Address'
    );

    /**
     * Minimum Passwor Length
     * @var int
     */
    public static $min_password_length = 6;

    /**
     * Possible Errors
     * @var array
     */
    public static $possible_errors = array(
        'invalid_email_address' => array(
            'message' => 'Invalid Email.',
            'fields'  => array('email_address')
        ),
        'invalid_password1' => array(
            'message' => 'Invalid Password.',
            'fields'  => array('password1')
        ),
        'password_too_short' => array(
            'message' => 'Choose a password of at least 6 characters.',
            'fields'  => array('password1')
        ),
        'invalid_password2' => array(
            'message' => 'You must re-enter your password',
            'fields'  => array('password2')
        ),
        'password_reentered_incorrectly' => array(
            'message' => 'Password was re-entered incorrectly. Try again.',
            'fields'  => array('password2', 'password1')
        ),
        'invalid_reset_hash' => array(
            'message' => 'Request another password reset, this token is no longer valid.',
            'fields' => array('password_reset_hash')
        ),
        'invalid_current_password' => array(
            'message' => 'The password you entered is incorrect.',
            'fields' => array('current_password')
        ),
        'duplicate_account' => array(
            'message' => 'You already have an account.',
            'type'    => 'duplicate_account',
            'fields' => array('email_address')
        )
    );

    /**
     * Run password validation
     */
    public function beforeCheckRequiredFields()
    {
        // if the passsword is being changed this method sets $this->password
        // validate_password takes care of the rest.
        $this->validatePassword();
    }

    /**
     * Validates Email
     * @param string $val
     */
    public function validate_email_address($val)
    {
        if (!$val) {
            return;
        }

        if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
            $this->addError('invalid_email_address');
        }

        if ($this->isInsert() && static::getByEmail($val)) {
            $this->addError('duplicate_account');
        }

        $this->email_email_address = trim($val);
    }

    /**
     * Gets a person by email address
     * @param $email
     */
    public static function getByEmail($email)
    {
        $email = addslashes(trim($email));
        return self::getOne(array(
            'where' => array("email_address ILIKE '{$email}'"),
            'order_by' => 'last_login_time DESC'
        ));
    }

    /**
     * validates password if the user is trying to change it using
     * $this->password1 and $this->password2
     * Otherwise, leaves it alone
     * It handles inserts and updates differently
     */
    protected function validatePassword()
    {
        if ($this->isInsert()) {

            if ($this->password || (!$this->password1 && !$this->password2)) {
                // the user is NOT setting a password on insert (we allow this)
                // for account creating for someone else (they get sent an email)
                // that tells them to set their password
                return;
            }

            if (!$this->password1) {
                $this->addError('invalid_password1');
                return;
            }

            if (!$this->password2) {
                $this->addError('invalid_password2');
                return;
            }

            if (!$this->checkPasswordEntered()) {
                return;
            }

            // set password to password1, this will automatically generate the hash etc.
            $this->password = $this->password1;
        }

        if ($this->isUpdate()) {

            // a password cannot be removed
            $this->addRequiredFields(array(
                'password' => 'Password'
            ));

            // the user is not attempting to change their own password via a form
            if (!$this->password1) return;

            // if passwords entered do not match
            if (!$this->checkPasswordEntered()) return;

            // need current password || password reset hash to update the password
            // using pw1 or pw2
            if (!$this->current_password && !$this->password_reset_hash) return;

            // load person to compare original password or reset_hash
            $o = new person($this->person_id);

            if ($this->current_password) {

                $hashed = Login::generateHash(
                    $this->current_password,
                    $this->generateUserSalt()
                );

                if ($hashed != $o->password_hash) {
                    $this->addError('invalid_current_password');
                } else {
                    $this->password = $this->password1;
                }

            } else if ($this->password_reset_hash) {

                if ($this->password_reset_hash != $o->password_reset_hash) {
                    $this->addError('invalid_reset_hash');
                    $this->saveProperties(array(
                        'password_reset_hash' => null
                    ));
                } else {
                    $this->password_reset_hash = null;
                    $this->password = $this->password1;
                }

            }
        }
    }

    /**
     * Checks if pw1 == pw2 and if not, adds an error to the stack
     * @return Bool
     */
    protected function checkPasswordEntered()
    {
        if ($this->password1 == $this->password2) {
            return true;
        }

        $this->addError('password_reentered_incorrectly');
        return false;
    }

    /**
     * Sets Password after insert
     */
    public function afterInsert()
    {
        $this->_postSavePassword();
    }

    /**
     * Generates a password reset hash
     * @todo Should throw Exception if no ID
     */
    public function generateResetHash()
    {
        return ($this->getID())
            ? $this->saveProperties(array(
                'password_reset_hash' => $this->makeResetHash()
            ))
            : null;
    }

    /**
     * Generates user salt
     * @todo Should throw Exception if no ID
     */
    public function generateUserSalt()
    {
        return ($this->getID())
            ? Login::generateUserSalt($this->getIDE())
            : null;
    }

    /**
     * Sets password
     * @param type $val
     */
    public function validate_password($val)
    {
        if (!$val) return;

        if (strlen($val) < static::$min_password_length) {
            $this->addError('password_too_short');
        }

        // only do this during an update because we cannot generate a salt
        // without an ID
        if ($this->isUpdate()) {
            $this->_data['password_hash'] = Login::generateHash(
                $val,
                $this->generateUserSalt()
            );
            // @todo: Uncomment this when we no longer have that dependency
            // $this->_data['password'] = null;
        }
    }

    /**
     * Updates last login time
     */
    public function updateLastLoginTime()
    {
        $this->saveProperties(array(
            'last_login_time' => 'now()'
        ));
    }

    /**
     * Creates a reset hash
     * @return  string
     */
    public function makeResetHash()
    {
        return sha1(mt_rand());
    }

}

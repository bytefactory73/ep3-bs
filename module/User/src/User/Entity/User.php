<?php

namespace User\Entity;

use Base\Entity\AbstractEntity;

class User extends AbstractEntity
{

    protected $uid;
    protected $alias;
    protected $status;
    protected $email;
    protected $pw;
    protected $login_attempts;
    protected $login_detent;
    protected $last_activity;
    protected $last_ip;
    protected $created;

    /**
     * The possible status options.
     *
     * @var array
     */
    public static $statusOptions = array(
        'placeholder' => 'Placeholder',
        'deleted' => 'Deleted user',
        'blocked' => 'Blocked user',
        'disabled' => 'Waiting for activation',
        'enabled' => 'Enabled user',
        'assist' => 'Assist',
        'admin' => 'Admin',
    );

    /**
     * Returns the status string.
     *
     * @return string
     */
    public function getStatus()
    {
        $status = $this->need('status');

        return self::$statusOptions[$status] ?? 'Unknown';
    }

    /**
     * The possible gender options.
     *
     * @var array
     */
    public static $genderOptions = array(
        'male' => 'Mr.',
        'female' => 'Mrs',
        'family' => 'Family',
        'firm' => 'Firm',
    );

    /**
     * Returns the gender string.
     *
     * @return string
     */
    public function getGender($default = null)
    {
        $gender = $this->getMeta('gender');

        if (is_null($gender)) {
            return $default;
        }

        return self::$genderOptions[$gender] ?? 'Unknown';
    }

    /**
     * The possible privileges.
     *
     * @var array
     */
    public static $privileges = array(
        'admin.user' => 'Can manage users',
        'admin.booking' => 'Can manage bookings',
        'admin.event' => 'Can manage events',
        'admin.config' => 'Can change configuration',
        'admin.see-menu' => 'Sees the admin menu',
        'calendar.see-past' => 'Sees past bookings',
        'calendar.see-data' => 'Sees names and data in calendar',
        'calendar.create-single-bookings' => 'Can create single bookings',
        'calendar.cancel-single-bookings' => 'Can cancel single bookings',
        'calendar.delete-single-bookings' => 'Can delete single bookings',
        'calendar.create-subscription-bookings' => 'Can create recurring bookings',
        'calendar.cancel-subscription-bookings' => 'Can cancel recurring bookings',
        'calendar.cancel-subscription-reservations' => 'Can cancel events of recurring bookings',
        'calendar.delete-subscription-bookings' => 'Can delete recurring bookings',
    );

    /**
     * Access control for this user.
     *
     * @param string $privileges
     * @return boolean
     */
    public function can($privileges)
    {
        if ($this->need('status') == 'admin') {
            return true;
        }

        if ($this->need('status') == 'assist') {
            if (is_array($privileges)) {
                $privileges = implode(',', $privileges);
            }

            if (is_string($privileges)) {
                $orPrivileges = explode(',', $privileges);
                $orPrivilegesMatched = 0;

                foreach ($orPrivileges as $orPrivilege) {
                    $andPrivileges = explode('+', $orPrivilege);
                    $andPrivilegesMatched = 0;

                    foreach ($andPrivileges as $andPrivilege) {
                        $privilege = trim($andPrivilege);

                        if ($this->getMeta('allow.' . $privilege) == 'true') {
                            $andPrivilegesMatched++;
                        }
                    }

                    if ($andPrivilegesMatched == count($andPrivileges)) {
                        $orPrivilegesMatched++;
                    }
                }

                if ($orPrivilegesMatched >= 1) {
                    return true;
                }
            }
        }

        return false;
    }

}

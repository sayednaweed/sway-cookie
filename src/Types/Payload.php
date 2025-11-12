<?php

namespace Sway\Types;

class Payload
{
    private $tokenable_id;
    private $type;
    private $expires_at;
    private $role_id;
    private $include_key;
    private $include_value;

    /**
     * Constructor to initialize the payload fields.
     *
     * @param mixed $tokenable_id
     * @param string $type
     * @param string $expires_at
     * @param string $role_id
     * @param string $include_key
     * @param string $include_value
     */
    public function __construct($tokenable_id, $type, string $expires_at, $role_id, $include_key = null, $include_value = null)
    {
        $this->tokenable_id = $tokenable_id;
        $this->expires_at = $expires_at;
        $this->type = $type;
        $this->role_id = $role_id;
        $this->include_key = $include_key;
        $this->include_value = $include_value;
    }
    /**
     * Get the tokenable ID.
     *
     * @return mixed
     */
    public function getTokenableId()
    {
        return $this->tokenable_id;
    }
    /**
     * Get the expiration time.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Get the expiration time.
     *
     * @return string
     */
    public function getExpiresAt()
    {
        return $this->expires_at;
    }
    /**
     * Get the Role id.
     *
     * @return string
     */
    public function getRoleId()
    {
        return $this->role_id;
    }
    /**
     * Get the Include Key.
     *
     * @return string
     */
    public function getIncludeKey()
    {
        return $this->include_key;
    }
    /**
     * Get the Include value.
     *
     * @return string
     */
    public function getIncludeValue()
    {
        return $this->include_value;
    }
}

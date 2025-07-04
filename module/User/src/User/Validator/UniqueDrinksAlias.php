<?php

namespace User\Validator;

use Zend\Validator\AbstractValidator;
use Zend\Db\Adapter\AdapterAwareInterface;
use Zend\Db\Adapter\Adapter;

class UniqueDrinksAlias extends AbstractValidator implements AdapterAwareInterface
{
    protected $adapter;
    protected $messageTemplates = [
        self::ALIAS_EXISTS => "Dieser Alias ist bereits vergeben.",
    ];
    const ALIAS_EXISTS = 'aliasExists';

    public function setDbAdapter(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    public function isValid($value, $context = null)
    {
        $this->setValue($value);
        if (!$this->adapter) {
            // No DB adapter, skip uniqueness check
            return true;
        }
        $userId = isset($context['user_id']) ? (int)$context['user_id'] : null;
        $sql = 'SELECT user_id FROM drink_aliases WHERE alias = ?';
        $params = [$value];
        $result = $this->adapter->query($sql, $params);
        $row = $result->current();
        if ($row && (!$userId || $row['user_id'] != $userId)) {
            $this->error(self::ALIAS_EXISTS);
            return false;
        }
        return true;
    }
}

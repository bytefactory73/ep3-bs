<?php

namespace User\Form;

use Zend\Form\Form;
use Zend\InputFilter\Factory;

class EditDrinksAliasForm extends Form
{
    public function init()
    {
        $this->setName('edaf');


        $this->add(array(
            'name' => 'edaf-alias',
            'type' => 'Text',
            'attributes' => array(
                'id' => 'edaf-alias',
                'style' => 'width: 235px;',
            ),
        ));

        // New dropdown for Bestell-Emails
        $this->add(array(
            'name' => 'edaf-order-email',
            'type' => 'Select',
            'attributes' => array(
                'id' => 'edaf-order-email',
                'style' => 'width: 235px;',
            ),
            'options' => array(
                'label' => 'Bestell-Emails',
                'value_options' => array(
                    'order' => 'Bei jeder Bestellung',
                    'summary' => 'Zusammenfassung (Nachts)',
                    'negative' => 'Nur wenn negativ',
                ),
                // 'empty_option' => 'Bitte wählen...',
            ),
        ));

        $this->add(array(
            'name' => 'edaf-submit',
            'type' => 'Submit',
            'attributes' => array(
                'value' => 'Speichern',
                'class' => 'default-button',
            ),
        ));

        $factory = new Factory();
        $this->setInputFilter($factory->createInputFilter(array(
            'edaf-alias' => array(
                'filters' => array(
                    array('name' => 'StringTrim'),
                ),
                'validators' => array(
                    array(
                        'name' => 'NotEmpty',
                        'options' => array(
                            'message' => 'Bitte gib einen Alias ein',
                        ),
                        'break_chain_on_failure' => true,
                    ),
                    array(
                        'name' => 'StringLength',
                        'options' => array(
                            'min' => 2,
                            'max' => 50,
                            'message' => 'Der Alias muss zwischen 2 und 50 Zeichen lang sein',
                        ),
                    ),
                    array(
                        'name' => 'User\Validator\UniqueDrinksAlias',
                        'options' => array(
                            'message' => 'Dieser Alias ist bereits vergeben.',
                        ),
                    ),
                ),
            ),
            'edaf-order-email' => array(
                'required' => false,
                'validators' => array(),
            ),
        )));
    }
}

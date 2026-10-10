<?php

namespace Backend\Form\Config;

use Zend\Form\Form;

class BehaviourTypeColorsForm extends Form
{

    public function init()
    {
        $this->setName('cf');

        $this->add(array(
            'name' => 'cf-type-colors',
            'type' => 'Textarea',
            'attributes' => array(
                'id' => 'cf-type-colors',
                'style' => 'width: 320px; height: 256px;',
            ),
            'options' => array(
                'label' => 'Booking type options',
                'notes' => 'One booking type per line and formatted as either:<br>Name<br>Name (internal value)<br>Name (internal value) Color<br>Name Color<br><br>For example:<br>Ranked game (ranked) #E67E22<br>Doubles #2980B9<br><br>Leave empty to disable booking types entirely. Bookings without a type keep their normal appearance.',
            ),
        ));

        $this->add(array(
            'name' => 'cf-submit',
            'type' => 'Submit',
            'attributes' => array(
                'value' => 'Save',
                'class' => 'default-button',
                'style' => 'width: 200px;',
            ),
        ));
    }

}

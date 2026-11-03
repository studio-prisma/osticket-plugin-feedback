<?php
// Form fields as plain value holders: the unit tests read the defaults.
class FormFieldStub
{
    public $options;
    public function __construct(array $options = array())
    {
        $this->options = $options;
    }
}
class SectionBreakField extends FormFieldStub {}
class BooleanField extends FormFieldStub {}
class ChoiceField extends FormFieldStub {}
class TextboxField extends FormFieldStub {}
class TextareaField extends FormFieldStub {}

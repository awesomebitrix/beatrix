<?php
namespace beatrix\form;

/**
 * Form renderer for Html output only
 */
class FormRenderer
{
    /**
     * @var Form
     */
    private $form;
    /**
     * @var array
     */
    private $inputPluginsRegistry = array();

    /**
     * Inject form instance
     * @param Form $form
     * @param array $options
     */
    public function __construct(Form $form, array $options = array())
    {
        $this->form = $form;
        if (isset($options['inputPlugins'])) {
            foreach ($options['inputPlugins'] as $alias => $class) {
                $this->inputPluginsRegistry[$alias] = $class;
            }
        }
    }

    /**
     * Versatile input rendering method
     * @param string $type
     * @param string $name
     * @param array $attributes
     * @return string
     */
    public function input($type = 'text', $name, array $attributes = array())
    {
        $formValue = $this->form->getValue($name);
        $attributes = array_merge($this->htmlValidationAttributes($name, $type), $attributes);
        switch ($type) {
            case 'text':
            case 'password':
            case 'color':
            case 'date':
            case 'datetime':
            case 'datetime-local':
            case 'email':
            case 'month':
            case 'number':
            case 'range':
            case 'search':
            case 'tel':
            case 'time':
            case 'url':
            case 'week':
                $attributes['value'] = array_get($attributes, 'value', $formValue);
                $attributes['name'] = self::resolveName($this->form->getNamespace(), $name);
                break;
            case 'checkbox':
                $isMultiple = isset($attributes['multiple']) && $attributes['multiple'];
                unset($attributes['multiple']);
                $attributes['name'] = self::resolveName($this->form->getNamespace(), $name)
                    . ($isMultiple ? '[]' : '');
                break;
            case 'radio':
                $attributes['name'] = self::resolveName($this->form->getNamespace(), $name);
                break;
            case 'textarea':
                $attributes['name'] = self::resolveName($this->form->getNamespace(), $name);
                return '<textarea ' . $this->renderAttributes($attributes) . '>' . $formValue . '</textarea>';
                break;
            default:
                return $this->runInputPlugin($type, $formValue, $name, $attributes);
                break;
        }
        $attributes['type'] = array_get($attributes, 'type', $type);
        return '<input ' . $this->renderAttributes($attributes) . '/>';
    }

    /**
     * @param string $type
     * @param string $name
     * @param array $choices List of [value => Label] items
     * @param array $inputAttributes
     * @return string
     * @internal param string $itemTemplate Item parts template, use these tokens: {labelOpen} {input} {labelText} {labelClose} {input} {labelText} {labelClose}
     */
    public function checkableList($type, $name, array $choices, array $inputAttributes = array())
    {
        $result = array();
        $itemTemplate = array_pull($inputAttributes, 'template', '{labelOpen} {input} {labelText} {labelClose}');
        if ($type == 'checkbox') {
            $inputAttributes['multiple'] = true;
        }
        $i = 0;
        foreach ($choices as $choice => $label) {
            $id = $name . '_id_' . $i++;
            $inputAttributes['id'] = $id;
            $labelStart = '<label for="' . $id . '">';
            $labelEnd = '</label>';
            $result[] = strtr($itemTemplate, array(
                '{labelOpen}' => $labelStart,
                '{labelClose}' => $labelEnd,
                '{labelText}' => $label,
                '{label}' => $labelStart . $label . $labelEnd,
                '{input}' => $this->checkable($type, $name, $choice, $inputAttributes),
            ));
        }
        return implode('', $result);
    }

    /**
     * Checkable (radio/checkbox) input. Checked state is assigned automatically depending on form data.
     * @param $type
     * @param $name
     * @param $value
     * @param array $attributes
     * @return string
     */
    public function checkable($type, $name, $value, array $attributes = array())
    {
        $checked = $this->getCheckedState($type, $name, $value);

        if ($checked) {
            $attributes['checked'] = 'checked';
        }
        $attributes['value'] = $value;
        return $this->input($type, $name, $attributes);
    }

    /**
     * Get the check state for a checkable input.
     *
     * @param  string $type
     * @param  string $name
     * @param  mixed $value
     * @return bool
     * @internal param bool $checked
     */
    protected function getCheckedState($type, $name, $value)
    {
        switch ($type) {
            case 'checkbox':
                return $this->getCheckboxCheckedState($name, $value);

            case 'radio':
                return $this->getRadioCheckedState($name, $value);

            default:
                throw new \UnexpectedValueException("Not checkable type: $type");
        }
    }

    /**
     * Get the check state for a checkbox input.
     *
     * @param  string $name
     * @param  mixed $value
     * @return bool
     * @internal param bool $checked
     */
    protected function getCheckboxCheckedState($name, $value)
    {
        $posted = $this->form->getValue($name);
        return is_array($posted) ? in_array($value, $posted) : (bool)$posted;
    }

    /**
     * Get the check state for a radio input.
     *
     * @param  string $name
     * @param  mixed $value
     * @return bool
     * @internal param bool $checked
     */
    protected function getRadioCheckedState($name, $value)
    {
        return $this->form->getValue($name) == $value;
    }

    /**
     * Create a select box field.
     *
     * @param  string $name
     * @param  array $choices
     * @param  array $attributes
     * @return string
     */
    public function select($name, $choices = array(), $attributes = array())
    {
        $selected = $this->form->getValue($name);

        if (!isset($attributes['name'])) {
            $attributes['name'] = $name;
        }
        if (isset($attributes['multiple']) && $attributes['multiple']) {
            $attributes['name'] = rtrim($attributes['name'], '[]') . '[]';
        }

        $html = array();

        foreach ($choices as $value => $display) {
            $html[] = $this->getSelectOption($display, $value, $selected);
        }

        $attributes = array_merge($attributes, $this->htmlValidationAttributes($name, 'select'));
        $attributes = self::renderAttributes($attributes);

        $choices = implode('', $html);

        return "<select {$attributes}>{$choices}</select>";
    }

    /**
     * Get the select option for the given value.
     *
     * @param  string $display
     * @param  string $value
     * @param  string $selected
     * @return string
     */
    public function getSelectOption($display, $value, $selected)
    {
        if (is_array($display)) {
            return $this->optionGroup($display, $value, $selected);
        }

        return $this->option($display, $value, $selected);
    }

    /**
     * Create a select element option.
     *
     * @param  string $display
     * @param  string $value
     * @param  string $selected
     * @return string
     */
    protected function option($display, $value, $selected)
    {
        $selected = $this->getSelectedValue($value, $selected);

        $attributes = array('value' => e($value), 'selected' => $selected);

        return '<option ' . self::renderAttributes($attributes) . '>' . e($display) . '</option>';
    }

    /**
     * Determine if the value is selected.
     *
     * @param  string $value
     * @param  string $selected
     * @return string
     */
    protected function getSelectedValue($value, $selected)
    {
        if (is_array($selected)) {
            return in_array($value, $selected) ? 'selected' : null;
        }

        return ((string)$value == (string)$selected) ? 'selected' : null;
    }

    /**
     * Create an option group form element.
     *
     * @param  array $list
     * @param  string $label
     * @param  string $selected
     * @return string
     */
    protected function optionGroup($list, $label, $selected)
    {
        $html = array();

        foreach ($list as $value => $display) {
            $html[] = $this->option($display, $value, $selected);
        }

        return '<optgroup label="' . e($label) . '">' . implode('', $html) . '</optgroup>';
    }

    /**
     * Render HTML tag attributes respecting special names
     * @param array $attributes [attr =>(string) value] Use #encode option to enable/disable special chars escaping.
     * @return string
     */
    public static function renderAttributes(array $attributes = [])
    {
        static $specialAttributes = array(
            'autofocus' => 1,
            'autoplay' => 1,
            'async' => 1,
            'checked' => 1,
            'controls' => 1,
            'declare' => 1,
            'default' => 1,
            'defer' => 1,
            'disabled' => 1,
            'formnovalidate' => 1,
            'hidden' => 1,
            'ismap' => 1,
            'itemscope' => 1,
            'loop' => 1,
            'multiple' => 1,
            'muted' => 1,
            'nohref' => 1,
            'noresize' => 1,
            'novalidate' => 1,
            'open' => 1,
            'readonly' => 1,
            'required' => 1,
            'reversed' => 1,
            'scoped' => 1,
            'seamless' => 1,
            'selected' => 1,
            'typemustmatch' => 1,
        );

        if ($attributes === array()) {
            return '';
        }

        $html = '';
        if (isset($attributes['#encode'])) {
            $raw = !$attributes['#encode'];
            unset($attributes['#encode']);
        } else {
            $raw = false;
        }

        foreach ($attributes as $name => $value) {
            if (isset($specialAttributes[$name])) {
                if ($value === false && $name === 'async') {
                    $html .= ' ' . $name . '="false"';
                } elseif ($value) {
                    $html .= ' ' . $name . '="' . $name . '"';
                }
            } elseif ($value !== null) {
                $html .= ' ' . $name . '="' . ($raw ? $value : self::encodeSpecialChars($value)) . '"';
            }
        }

        return $html;
    }

    private static function resolveName($namespace, &$fieldName)
    {
        if (!$namespace) {
            return $fieldName;
        }
        if (($pos = strpos($fieldName, '[')) !== false) {
            if ($pos !== 0) {  // e.g. name[a][b]
                return $namespace . '[' . substr($fieldName, 0, $pos) . ']' . substr($fieldName, $pos);
            }
            if (preg_match('/\](\w+\[.*)$/', $fieldName, $matches)) {
                $name = $namespace . '[' .
                    str_replace(']', '][', trim(strtr($fieldName, array('][' => ']', '[' => ']')), ']')) . ']';
                $fieldName = $matches[1];
                return $name;
            }
        }
        return $namespace . '[' . $fieldName . ']';
    }

    public static function encodeSpecialChars($text)
    {
        return htmlspecialchars($text, ENT_QUOTES, 'utf-8');
    }

    /**
     * @param $name
     * @param $type
     * @return array
     */
    private function htmlValidationAttributes($name, $type)
    {
        $rules = $this->form->getRulesCollection($name);
        if (!$rules) {
            return array();
        }
        $attributes = array();
        foreach ($rules as $validator) {
            $renderer = new ValidatorRenderer($validator);
            $attributes = array_merge($attributes, $renderer->getHtmlAttributes($type));
        }
        return $attributes;
    }

    /**
     * @return Form
     */
    public function getForm()
    {
        return $this->form;
    }

    /**
     * @param $type
     * @param $inputValue
     * @param $name
     * @param $attributes
     * @return string
     */
    private function runInputPlugin($type, $inputValue, $name, array $attributes)
    {
        if (isset($this->inputPluginsRegistry[$type])) {
            $class = $this->inputPluginsRegistry[$type];
            /** @var FormInputInterface $plugin */
            $plugin = new $class($this);
            return $plugin->renderInput($name, $inputValue, $attributes);
        }
        throw new \UnexpectedValueException("No [$type] input registered. Use renderer's __construct options");
    }
}
<?php
/**
 * Field
 *
 * Abstracts general fields parameters (type, value, name) and
 * reforms a correct form field depending on what was asked
 */
namespace Former\Traits;

use \Former\Form\ControlGroup;
use \Former\Form\Form;
use \Former\Interfaces\FieldInterface;
use \Former\LiveValidation;

abstract class Field extends FormerObject implements FieldInterface
{
  /**
   * The field type
   * @var string
   */
  protected $type;

  /**
   * Illuminate application instance
   * @var Illuminate\Foundation\Application  $app
   */
  protected $app;

  /**
   * The Form instance
   * @var Former\Form
   */
  protected $form;

  /**
   * The field value
   * @var string
   */
  protected $value;

  /**
   * A label for the field (if not using Bootstrap)
   * @var string
   */
  protected $label = array(
    'label'      => null,
    'attributes' => array()
  );

  /**
   * The field's control group
   * @var ControlGroup
   */
  protected $controlGroup;

  ////////////////////////////////////////////////////////////////////
  //////////////////////////// INTERFACE /////////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Set up a Field instance
   *
   * @param string $type A field type
   */
  public function __construct($app, $type, $name, $label, $value, $attributes)
  {
    // Set base parameters
    $this->app        = $app;
    $this->attributes = (array) $attributes;
    $this->label($label);
    $this->name       = $name;
    $this->type       = $type;
    $this->value      = $value;

    // Set magic parameters (repopulated value, translated label, etc)
    if($this->app['config']->get('former::automatic_label')) $this->ponder($name, $label);
    if($type != 'password') $this->value = $this->repopulate();
    if ($this->app['config']->get('former::live_validation')) {
      new LiveValidation($this, $this->getRules());
    }


    // Link Control group
    if ($this->app['former.framework']->isnt('Nude')) {
      $this->controlGroup = new ControlGroup($this->app, $this->label);
    }
  }

  /**
   * Redirect calls to the control group if necessary
   */
  public function __call($method, $parameters)
  {
    // Redirect calls to the Control Group
    if (method_exists($this->controlGroup, $method)) {
      call_user_func_array(array($this->controlGroup, $method), $parameters);

      return $this;
    }

    return parent::__call($method, $parameters);
  }

  /**
   * Prints out the field
   *
   * @return string
   */
  public function __toString()
  {
    // Dry syntax (hidden fields, plain fields)
    if ($this->isUnwrappable()) $html = $this->render();

    // Control group syntax
    elseif ($this->app['former.framework']->isnt('Nude') and Form::isOpened()) {
      $html = $this->controlGroup->wrapField($this);
    }

    // Classic syntax
    else {
      $html  = $this->app['former.framework']->createLabelOf($this);
      $html .= $this->render();
    }

    return $html;
  }

  ////////////////////////////////////////////////////////////////////
  /////////////////////////// FUNCTIONS //////////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Whether the current field is required or not
   *
   * @return boolean
   */
  public function isRequired()
  {
    return isset($this->attributes['required']);
  }

  /**
   * Check if a field is unwrappable (no label)
   *
   * @return boolean
   */
  public function isUnwrappable()
  {
    return
      $this->app['former']->form() and $this->app['former']->form()->type == 'inline' or
      in_array($this->type, array('hidden', 'submit', 'button', 'reset'));
  }

  /**
   * Check if field is a checkbox or a radio
   *
   * @return boolean
   */
  public function isCheckable()
  {
    return in_array($this->type, array('checkboxes', 'radios'));
  }

  /**
   * Get the rules applied to the current field
   *
   * @return array An array of rules
   */
  public function getRules()
  {
    return $this->app['former']->getRules($this->name);
  }

  /**
   * Get the field's type
   *
   * @return string
   */
  public function getType()
  {
    return $this->type;
  }

  /**
   * Change a field's type
   *
   * @param string $type
   */
  public function setType($type)
  {
    $this->type = $type;
  }

  ////////////////////////////////////////////////////////////////////
  //////////////////////// SETTERS AND GETTERS ///////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Adds a label to the control group/field
   *
   * @param  string $text       A label
   * @param  array  $attributes The label's attributes
   * @return Field              A field
   */
  public function label($text, $attributes = array())
  {
    $label = array(
      'text'       => $this->app['former.helpers']->translate($text),
      'attributes' => $attributes);

    if($this->controlGroup) $this->controlGroup->setLabel($label);
    else $this->label = $label;
    return $this;
  }

  /**
   * Set the Field value no matter what
   *
   * @param string $value A new value
   */
  public function forceValue($value)
  {
    $this->value = $value;

    return $this;
  }

  /**
   * Classic setting of attribute, won't overwrite any populate() attempt
   *
   * @param  string $value A new value
   */
  public function value($value)
  {
    // Check if we already have a value stored for this field or in POST data
    $already = $this->repopulate();

    if(!$already) $this->value = $value;

    return $this;
  }

  /**
   * Change the field's name
   *
   * @param  string $name The new name
   */
  public function name($name)
  {
    $this->name = $name;

    // Also relink the label to the new name
    $this->label($name);

    return $this;
  }

  ////////////////////////////////////////////////////////////////////
  //////////////////////////////// HELPERS ///////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Use values stored in Former to populate the current field
   */
  private function repopulate($fallback = null)
  {
    if(is_null($fallback)) $fallback = $this->value;

    // Get values from POST, populated, and manually set value
    $post     = $this->app['former']->getPost($this->name);
    $populate = $this->app['former']->getValue($this->name);

    // Assign a priority to each
    if(!is_null($post)) $value = $post;
    elseif(!is_null($populate)) $value = $populate;
    else $value = $fallback;
    return $value;
  }

  /**
   * Ponders a label and a field name, and tries to get the best out of it
   *
   * @param  string $label A label
   * @param  string $name  A field name
   * @return array         A label and a field name
   */
  private function ponder($name, $label)
  {
    // Check for the two possibilities
    if($label and is_null($name)) $name = \Str::slug($label);
    elseif(is_null($label) and $name) $label = $name;

    // Attempt to translate the label
    $label = $this->app['former.helpers']->translate($label);

    // Save values
    $this->name  = $name;
    $this->label($label);
  }
}
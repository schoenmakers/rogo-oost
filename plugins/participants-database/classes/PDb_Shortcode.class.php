<?php

/*
 * Shortcode class
 *
 * provides basic functionality for rendering a shortcode's output
 *
 * common functioality we will handle here:
 *  choosing a template
 *  capturing the output of the template
 *  loading the plugin settings
 *  defining the default shortcode attributes array
 *  setting up the shortcode attributes array
 *  maintaining loop pointers
 *  instantiating Field_Group and Field objects for the display loop
 *  converting dynamic value notation to the value it represents
 *  perfoming a field key replace on blocks of text for emails and user feedback
 *
 */

abstract class PDb_Shortcode {

  // properties
  // class name of the shortcode-specific subclass
  protected $shortcode_class;
  // name stem of the shortcode
  public $module;
  // the instance of the class for singleton pattern
  public static $instance;
  // holds the name of the template
  protected $template_name;
  // holds the template file path
  protected $template;
  // holds the output for the shortcode
  protected $output = '';
  // plugin options array
  protected $options;
  // default values for standard shortcode attributes
  protected $shortcode_defaults;
  // holds the current shorcode attributes
  protected $shortcode_atts;
  // a selected array of fields to display
  var $display_columns = false;
  // holds the field groups array which will contain all the groups info and their fields
  // this will be the main object the template iterates through
  var $record;
  // holds the current record ID
  var $participant_id;
  // the array of current record fields; false if the ID is invalid
  var $participant_values;
  // holds the URL to the participant record page
  var $registration_page;
  // an identifier for the type of captcha to use (not implemented yet)
  protected $captcha_type;
  // holds any validation error html generated by the validation class
  protected $error_html = '';
  // holds the module wrap class name
  var $wrap_class;
  // the class name to apply to empty fields
  protected $emptyclass = 'blank-field';
  // holds the pagination object
  var $pagination;
  // the current Field_Group object
  var $group;
  // the number of displayable groups in the record
  var $group_count;
  // group iteration pointer
  var $current_group_pointer = 1;
  // the current Field object
  var $field;
  // field iteration pointer
  var $current_field_pointer = 1;
  // all the records are held in this array
  var $records;
  // the number of records after filtering
  var $num_records;
  // record iteration pointer
  var $current_record_pointer = 1;
  // an array of all field objects used by the shortcode
  var $columns = array();

  /**
   * instantiates the shortcode object
   *
   * @param object $subclass the instantiating object
   * @param array  $params   the raw parameters passed in from the shortcode
   * @param array  $add_atts additional shortcode attributes to use as defined
   *                         in the instantiating subclass
   *
   */
  public function __construct($subclass, $params, $add_atts = array()) {
    
    $this->wrap_class = Participants_Db::$css_prefix . $this->module;

    // set the global shortcode flag
    Participants_Db::$shortcode_present = true;

    $this->module = $subclass->module;
    $this->options = Participants_Db::$plugin_options;
    $this->shortcode_class = get_class($subclass);

    $this->shortcode_defaults = array(
        'title' => '',
        'captcha' => 'none',
        'class' => Participants_Db::$css_prefix . $this->module,
        'template' => 'default',
        'fields' => '',
    );

    // set up the shortcode_atts property
    $this->_set_attributes($params, $add_atts);

    $this->_set_display_columns();

    $this->wrap_class = trim($this->wrap_class) . ' ' . trim($this->shortcode_atts['class']);

    //$this->captcha_type = $this->shortcode_atts['captcha'];
    // set the template to use
    $this->set_template($this->shortcode_atts['template']);
  }

  /**
   * dumps the output of the template into the output property
   *
   */
  protected function _print_from_template() {

    if (false === $this->template) {

      $this->output = '<p class="alert alert-error">' . sprintf(_x('<%1$s>The template %2$s was not found.</%1$s> Please make sure the name is correct and the template file is in the correct location.', 'message to show if the plugin cannot find the template', 'participants-database'), 'strong', $this->template) . '</p>';

      return false;
    }

    ob_start();

    // this will be included in the subclass context
    $this->_include_template();

    $this->output = ob_get_clean();
  }

  /**
   * sets up the template
   *
   * sets the template properties of the object
   *
   * @param string $name the name stem of the specified template
   * 
   */
  protected function set_template($name) {

    $this->template_name = $name;
    $this->_find_template();
  }

  /**
   * selects the template to use
   *
   */
  private function _find_template() {

    $template = get_stylesheet_directory() . '/templates/pdb-' . $this->module . '-' . $this->template_name . '.php';

    if (!file_exists($template)) {

      $template = Participants_Db::$plugin_path . '/templates/pdb-' . $this->module . '-' . $this->template_name . '.php';
    }

    if (!file_exists($template)) {

      $template = Participants_Db::$plugin_path . '/templates/pdb-' . $this->module . '-default.php';
    }

    if (!file_exists($template)) {

      error_log(__METHOD__ . ' template not found: ' . $template);

      $template = false;
    }

    $this->template = $template;
  }

  /**
   * includes the shortcode template
   *
   * this is a dummy function that must be defined in the subclass because the
   * template has to be included in the subclass context
   */
  abstract protected function _include_template();

  /**
   * sets up the shortcode attributes array
   *
   * @param array $params raw parameters passed in from the shortcode
   * @param array $add_atts an array of subclass-specific attributes to add
   */
  private function _set_attributes($params, $add_atts) {

    $defaults = array_merge($this->shortcode_defaults, $add_atts);

    $this->shortcode_atts = shortcode_atts($defaults, $params);
  }

  /**
   * outputs a "record not found" message
   *
   * the message is defined int he plugin settings
   */
  protected function _not_found() {

    $this->output = empty($this->options['no_record_error_message']) ? '' : '<p class="alert alert-error">' . $this->options['no_record_error_message'] . '</p>';
  }

  /**
   * collects any validation errors from the last submission
   *
   */
  protected function _get_validation_errors() {

    if (is_object(Participants_Db::$validation_errors)) {

      $this->error_html = Participants_Db::$validation_errors->get_error_html();
    }
  }

  /**
   * prints the error messages html
   *
   * @param string $container wraps the whole error message element, must include
   *                          2 %s placeholders: first for a class name, then one for the content
   * @param string $wrap      wraps each error message, must have %s placeholders for the content.
   *
   */
  public function print_errors($container = false, $wrap = false) {

    if (is_object(Participants_Db::$validation_errors)) {

      if ($container)
        Participants_Db::$validation_errors->set_error_html($container, $wrap);

      echo Participants_Db::$validation_errors->get_error_html();
    }

    //echo $this->error_html;
  }
  
  /**
   * gets the current errors
   * 
   * @return mixed an array of error messages, or bool false if no errors
   */
  public function get_errors() {

    if (is_object(Participants_Db::$validation_errors)) {

      $errors = Participants_Db::$validation_errors->get_validation_errors();
      if ($this->_empty($errors)) return false;
      else return $errors;
    }
  }

  /*   * **************
   * ITERATION CONTROL

    /**
   * checks if there is still another group of fields to show
   *
   */

  public function have_groups() {

    return $this->current_group_pointer <= $this->group_count;
  }

  /**
   * gets the next group
   *
   * increments the group pointer
   *
   */
  public function the_group() {

    // the first time through, use current()
    if ($this->current_group_pointer == 1)
      $this->group = new Field_Group_Item(current($this->record), $this->module);
    else
      $this->group = new Field_Group_Item(next($this->record), $this->module);

    $this->reset_field_counter();

    $this->current_group_pointer++;
  }

  /**
   * checks if there is still another field to show
   *
   * @param object $group the current group out of the iterator
   */
  public function have_fields() {

    $field_count = is_object($this->group) ? $this->group->_field_count : count($this->display_columns);

    return $this->current_field_pointer <= $field_count;
  }

  /**
   * gets the next field; advances the count
   *
   */
  public function the_field() {

    // the first time through, use current()
    if ( $this->current_field_pointer == 1 ) {
      if (is_object($this->group) )
       $this->field = new Field_Item( current($this->group->fields) );
      else
       $this->field = new Field_Item( current($this->record->fields), $this->record->record_id );
    } else {
      if (is_object($this->group) )
        $this->field = new Field_Item( next($this->group->fields) );
      else
        $this->field = new Field_Item( next($this->record->fields), $this->record->record_id );
    }

    if ($this->field->form_element == 'hidden') {

      // print the hidden field
      $this->field->_print();

      // advance the pointer
      $this->current_field_pointer++;

      // and call the next field
      $this->the_field();
    } else {
      
      $this->current_field_pointer++;
    }
  }

  /**
   * resets the field counter
   */
  protected function reset_field_counter() {

    $this->current_field_pointer = 1;
  }

  /**
   * checks for additional records to show
   */
  public function have_records() {

    $remaining = $this->num_records - ( ( $this->pagination->page - 1 ) * $this->shortcode_atts['list_limit'] );

    $records_this_page = $remaining < $this->shortcode_atts['list_limit'] ? $remaining : $this->shortcode_atts['list_limit'];

    return $this->current_record_pointer <= $records_this_page;
  }

  /**
   * gets the next group
   *
   * increments the group pointer
   *
   */
  public function the_record() {

    // the first time through, use current()
    if ($this->current_record_pointer == 1) {

      $the_record = current($this->records);
    } else {

      $the_record = next($this->records);
    }

    $this->record = new Record_Item($the_record, key($this->records));

    $this->reset_field_counter();

    $this->current_record_pointer++;
  }

  /**
   * sets up the template iteration object
   *
   * this takes all the fields that are going to be displayed and organizes them
   * under their group so we can easily run through them in the template
   */
  protected function _setup_iteration() {

    $this->record = new stdClass;

    foreach (Participants_Db::get_groups('`title`,`name`,`description`', $this->_get_display_groups(false)) as $group) {

      if ($this->_has_group_fields($group['name'])) {

        //add the group array as an object
        $this->record->$group['name'] = (object) $group;
        // create an object for the groups fields
        $this->record->$group['name']->fields = new stdClass();

        //error_log ( __METHOD__.' group fields: '. print_r( $this->_get_group_fields( $group['name'] ), 1 )  );

        foreach ($this->_get_group_fields($group['name']) as $field) {
          
          // add the module property
          $field->module = $this->module;

          // set the current value of the field
          $this->_set_field_value($field);

          // add the field to the list of fields
          $this->columns[$field->name] = $field;

          /*
           * add the field object to the record object
           */
          $this->record->$group['name']->fields->{$field->name} = $field;

          //error_log( __METHOD__.' field:'.print_r( $field,1 ) ) ;
        }
      }
    }

    // save the number of groups
    $this->group_count = count((array) $this->record);
  }

  /*   * **************
   * RECORD FIELDS
   */
  
  /**
   *  gets the field attribues for named field
   */
  protected function _get_record_field( $field_name ) {
    
    global $wpdb;
    
    $columns = array( 'name','title','default','help_text','form_element','validation','readonly','values');
    
    $sql = 'SELECT v.'. implode( ',v.',$columns ) . ' 
            FROM '.Participants_Db::$fields_table.' v 
            WHERE v.name = "'.$field_name.'" 
            ';
    
    $sql .= ' ORDER BY v.order';
            
    return current( $wpdb->get_results( $sql, OBJECT_K ) );
  
  }
  
  
  /****************
   * FIELD GROUPS
   */
  
  /**
   * gets the field attribues for all fields in a specified group
   */
  private function _get_group_fields( $group ) {
    
    global $wpdb;
    
    $columns = array( 'name','title','default','help_text','form_element','validation','readonly','values');
    
    $sql = 'SELECT v.'. implode( ',v.',$columns ) . ' 
            FROM '.Participants_Db::$fields_table.' v 
            WHERE v.group = "'.$group.'" 
            ';
    switch ( $this->module ) {
      
      case 'signup':
      case 'thanks':
        
        $sql .= ' AND v.signup = 1';
        break;
            
    }
    
    if (is_array($this->display_columns)) {
      $sql .= ' AND v.name IN ("' . implode('","',$this->display_columns) . '")';
    }
    
    // this orders the hidden fields at the top of the list
    $sql .= ' ORDER BY v.form_element = "hidden" DESC, v.order';
            
    return $wpdb->get_results( $sql, OBJECT_K );
  
  }
  
  /**
   * gets the field attribues for all fields in a specified group
   */
  private function _has_group_fields( $group ) {
    
    global $wpdb;
    
    $sql = 'SELECT count(*)  
            FROM '.Participants_Db::$fields_table.' v 
            WHERE v.group = "%s" 
            ';
    switch ( $this->module ) {
      
      case 'signup':
      case 'thanks':
        
        $sql .= ' AND v.signup = 1';
        break;
            
    }
    
    $sql .= ' ORDER BY v.order';
            
    $result = $wpdb->get_var( $wpdb->prepare($sql,$group ) );
    
    return (bool) $result > 0;
  
  }
  
  /**
   * gets only display-enabled groups
   *
   * @param  bool $logic true to get display-enabled groups, false to get non-enabled groups
   * @return string comma-separated list of group names
   */
  private function _get_display_groups( $logic = true ) {
    
    global $wpdb;
    
    $sql = 'SELECT g.name 
            FROM '.Participants_Db::$groups_table.' g
            WHERE g.display = '.( $logic ? 1 : 0 );
            
    $result = $wpdb->get_results( $sql, ARRAY_N );
    
    foreach ( $result as $group ) $return[] = current( $group ); 
    
    return $return;
  
  }
  
  /****************
   * FIELD VALUES
	 
  /**
   * sets the field value
   *
   *
   * @param object $field the current field object
   * @return string the value of the field
   */
  protected function _set_field_value( &$field ) {
    
    // get the existing value if any
		$value = isset( $this->participant_values[ $field->name ] ) ? Participants_Db::unserialize_array( $this->participant_values[ $field->name ] ) : '';
		
		// replace it with the new value if provided, escaping the input
		if ( isset( $_POST[ $field->name ] ) ) {
			
			$value = $this->_esc_submitted_value( $_POST[ $field->name ] );
			
		}
			
		switch ( $field->form_element ) {
      
      case 'text-line':

        // show the default value for empty read-only text-lines
        if ($field->readonly == 1 and $this->module == 'list' and empty($value)) $value = $field->default;
        break;
			
			case 'image-upload':
			
				$value = empty( $value ) ? '' : $value;
				
				break;
				
			case 'multi-select-other':
			case 'multi-checkbox':
			
				$value = is_array( $value ) ? $value : explode( ',', $value );
				
				break;
			
			case 'password':
				
				$value = '';
				break;
				
			case 'hidden':
				
				/* use the dynamic value if the shortcode is signup, otherwise only use the dynamic 
         * value in the record module if there is no previously set value
				 */
				if ( $this->module == 'signup' or ( empty( $value ) and $this->module == 'record' ) ) $value = $this->get_dynamic_value( $field->default );
        break;
				
		}
    
    // set the value property of the field object
    $field->value = $value;
    
  }
  
  /**
   * builds a validated array of selected fields
   * 
   * this looks for the 'field' attribute in the shortcode and if it finds it, goes 
   * through the list of selected fields and sets up an array of valid fields that 
   * can be used in a database query 
   */
  protected function _set_display_columns() {
    
    if (isset($this->shortcode_atts['fields'])) {

      $raw_list = explode(',', str_replace(array("'", '"', ' ', "\r"), '', $this->shortcode_atts['fields']));

      if (is_array($raw_list)) :
      
        foreach ($raw_list as $column) {

          if (Participants_Db::is_column($column)) {

            $this->display_columns[] = $column;
          }
        }

      endif;
    }
    
    if ($this->module == 'list' and ! is_array($this->display_columns)) {
      $this->display_columns = Participants_Db::get_list_display_columns('display_column');
    }
  }
  
  /**
   * escape a value from a form submission
   *
   * can handle both single values and arrays
   */
  protected function _esc_submitted_value( $value ) {
    
    $value = Participants_Db::unserialize_array( $value );
    
    if ( is_array( $value ) ) {
      
      $return = array();
      foreach ( $value as $k => $v ) $return[$k] = $this->_esc_value( $v );
      
    } else {
      
      $return = $this->_esc_value( $value );
      
    }
    
    return $return;
    
  }
  
  /**
   * escape a value from a form submission
   */
  private function _esc_value( $value ) {
    
    return esc_html( stripslashes( $value ) );
    
  }
  
  /**
   * parses the value string and obtains the corresponding dynamic value
   *
   * the object property pattern is 'object->property' (for example 'curent_user->name'), and the presence of the
   * '->'string identifies it.
   * 
   * the global pattern is 'global_label:value_name' (for example
   * 'SERVER:HTTP_HOST') and the presence of the ':' identifies it.
   *
   * if there is no indicator, the field is treated as a constant
   *
   * @param string $value the current value of the field as read from the
   *                      database or in the $_POST array
   *
   */
  public function get_dynamic_value( $value ) {
  
    if ( false !== strpos( html_entity_decode($value), '->' ) ) {
        
			// set the $current_user global
			get_currentuserinfo();
				
      global $post, $current_user;
      
      list( $object, $property ) = explode( '->', html_entity_decode($value) );
      
      $object = ltrim( $object, '$' );
      
      if ( is_object( $$object ) ) {
      
        $value = isset( $$object->$property ) ?  $$object->$property : $value;
        
      }
      
    } elseif ( false !== strpos( html_entity_decode($value),':' ) ) {
      
      list( $global,$name ) = explode( ':', html_entity_decode( $value ) );
      
      // clean this up in case some one puts $_SERVER instead of just SERVER
      $global = preg_replace( '#^[$_]{1,2}#', '', $global );
      
      /*
       * for some reason getting the superglobal array directly with the string
       * is unreliable, but this bascially works as a whitelist, so that's
       * probably not a bad idea.
       */
      switch ( strtoupper( $global ) ) {
        
        case 'SERVER':
          $global = $_SERVER;
          break;
        case 'REQUEST':
          $global = $_REQUEST;
          break;
        case 'COOKIE':
          $global = $_COOKIE;
          break;
        case 'POST':
          $global = $_POST;
          break;
        case 'GET':
          $global = $_GET;
          
      }
      
      $value = isset( $global[$name] ) ? $global[$name] : $value;
      
    }
    
    return $value;
    
  }

  /**
   * replace the tags in text messages
   *
   * a tag contains the column name for the value to use: [column_name]
   *
   * also processes the [record_link] tag
   *
   * @param string $text   the unporcessed text with tags
   * @param array  $values the values to replace the tags with
   * @param array  $tags   the tags to look for in the text
   *
   * @return string the text with the replacements made
   *
   */
	protected function _proc_tags( $text, $values = array(), $tags = array() ) {

		if ( empty( $values ) ) {

			foreach( $this->columns as $column ) {

				$tags[] = '['.$column->name.']';

				$values[] = Participants_Db::prep_field_for_display( $this->participant_values[$column->name], $column->form_element, false );

			}

		}

		// add some extra tags
    foreach( array('id','private_id') as $v ) {
      
      $tags[] = '['.$v.']';
      $values[] = $this->participant_values[$v];
    }
		$tags[] = '[record_link]';
		$values[] = $this->registration_page;
    
    $tags[] = '[admin_record_link]';
    $values[] = Participants_Db::get_admin_record_link($this->participant_values['id']);

		$placeholders = array();
		
		for ( $i = 1; $i <= count( $tags ); $i++ ) {

			$placeholders[] = '%'.$i.'$s';

		}

		// replace the tags with variables
		$pattern = str_replace( $tags, $placeholders, $text );
		
		// replace the variables with strings
		return vsprintf( $pattern, $values );

	}
  
  /**
   * closes the form tag
   */
  protected function print_form_close() {
    
    echo '</form>';
    
  }
  
  /**
   * prints an empty class designator
   *
   * @param object Field object
   * @return string the class name
   */
  public function get_empty_class( $Field ) {
		
		$emptyclass = 'image-upload' == $Field->form_element ? 'image-'.$this->emptyclass : $this->emptyclass ;
    
    return ( $this->_empty( $Field->value ) ? $emptyclass : '' );
    
  }
	
	/**
	 * tests a value for emptiness
	 *
	 * needed primarliy because we can have arrays of empty elements which will
	 * not test empty using PHP's empty() function. Also, a zero is non-empty.
	 *
	 * @param mixed $value the value to test
	 * @return bool
	 */
	protected function _empty( $value ) {
		
		// if it is an array, collapse it
		if ( is_array( $value ) ) $value = implode( '', $value );
		
		return empty( $value ) or (empty( $value ) && $value !== 0);
		
	}
  
  
}
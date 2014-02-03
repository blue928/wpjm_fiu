<?php

/**
 * Plugin Name: WP-Job-Manager-Additional-Fields
 * Plugin URI:  https://github.com/blue928/wpjm_fiu
 * Description: This adds the Featured Image Upload field to the Job Submission Form
 * Author:      Blue Presley
 * Author URI:  http://astoundify.com
 * Version:     1.0
 */
/**
 * TODO:
 * 
 * 1) prevent attachments from being created over and over
 * 2) validate featured image size
 */
// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class Astoundify_Job_Manager_Fields {

    /**
     * @var $instance
     */
    private static $instance;

    /**
     * Make sure only one instance is only running.
     *
     * @since Custom fields for WP Job Manager 1.0
     *
     * @param void
     * @return object $instance The one true class instance.
     */
    public static function instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Start things up.
     *
     * @since Custom fields for WP Job Manager 1.0
     *
     * @param void
     * @return void
     */
    public function __construct() {
        $this->setup_globals();
        $this->setup_actions();
    }

    /**
     * Set some smart defaults to class variables.
     *
     * @since Custom fields for WP Job Manager 1.0
     *
     * @param void
     * @return void
     */
    private function setup_globals() {
        $this->file = __FILE__;

        $this->basename = plugin_basename($this->file);
        $this->plugin_dir = plugin_dir_path($this->file);
        $this->plugin_url = plugin_dir_url($this->file);
    }

    /**
     * Hooks and filters.
     *
     * We need to hook into a couple of things:
     * 1. Output fields on frontend, and save.
     * 2. Output fields on backend, and save (done automatically).
     *
     * @since Custom fields for WP Job Manager 1.0
     *
     * @param void
     * @return void
     */
    private function setup_actions() {
        /**
         * Filter the default fields that ship with WP Job Manager.
         * The `form_fields` method is what we use to add our own custom fields.
         */
        add_filter('submit_job_form_fields', array($this, 'form_fields'));

        /**
         * When WP Job Manager is saving all of the default field data, we need to also
         * save our custom fields. The `update_job_data` callback is what does this.
         */
        add_action('job_manager_update_job_data', array($this, 'update_job_data'), 10, 2);

        /**
         * Let's add another job_manager_update_job_data action that fires immediately after the
         * above one.
         */
        add_action('job_manager_update_job_data', array($this, 'set_featured_listing_image'), 11, 2);

        /**
         * Filter the default fields that are output in the WP admin when viewing a job listing.
         * The `job_listing_data_fields` adds the same fields to the backend that we added to the front.
         *
         * We do not need to add an additional callback for saving the data, as this is done automatically.
         */
        add_filter('job_manager_job_listing_data_fields', array($this, 'job_listing_data_fields'));
    }

    /**
     * When a post is saved or updated, set the featured image if possible
     * 
     * When a new job is created, or a job is updated, check the custom
     * 'featured_listing_upload' field. If there is a value, make sure it conforms
     * to the dimensions the featured image needs to show correctly for the theme. If so
     * add it to the media library dynamicaly and set it
     */
    function set_featured_listing_image($job_id, $values) {
        //echo get_post_meta($job_id, '_featured_listing_upload', true);
        
        
        // check to see if a job already has a featured image by counting
        // its attachments. If so, return.
        if ($this->has_featured_image($job_id)) {
            return false;
        }

        // Get featured listing upload value
        // Note: there is no need to check if it's a valid image. WP
        // Job Manager has done this for us by this point with its upload_image
        // function.
        $image_url = get_post_meta($job_id, '_featured_listing_upload', true);

        $upload_dir = ABSPATH . 'wp-content/uploads/job_listing_images';
        
        $filename = basename($image_url); // Create image file name
        
        $file = $upload_dir . '/' . $filename;
        
        // Make sure the file conforms to required size specifications
        if(!$this->is_correct_image_size($file)){
            return false;
        }

        // Check image file type
        $wp_filetype = wp_check_filetype($filename, null);

        //set attachment data
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // create the attachment
        $attach_id = wp_insert_attachment($attachment, $file, $job_id);

        // Include image.php
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Define attachment metadata
        $attach_data = wp_generate_attachment_metadata($attach_id, $file);

        // Assign metadata to attachment
        wp_update_attachment_metadata($attach_id, $attach_data);

        // And finally assign featured image to post
        set_post_thumbnail($job_id, $attach_id);


        print "<br>The Filename is: " . $filename . "<br>";
        print "<br>It's located at: " . $file . "<br>";
        print "<br>The filetype is: " . $wp_filetype['type'] . "<br>";
    }

    /**
     * Add fields to the front end submission form.
     *
     * Currently the fields must fall between two sections: "job" or "company". Until
     * WP Job Manager filters the data that passes to the registration template, these are the
     * only two sections we can manipulate.
     *
     * You may use a custom field type, but you will then need to filter the `job_manager_locate_template`
     * to search in `/templates/form-fields/$type-field.php` in your theme or plugin.
     *
     * @since Custom fields for WP Job Manager 1.0
     *
     * @param array $fields The existing fields
     * @return array $fields The modified fields
     */
    function form_fields($fields) {


        $fields['company']['featured_listing_upload'] = array(
            'label' => 'Featured Listing Image', // The label for the field
            'type' => 'file', // file, job-description (tinymce), select, text
            'placeholder' => '', // Placeholder value
            'required' => false, // If the field is required to submit the form
            'priority' => 10                 // Where should the field appear based on the others
        );

        /**
         * Repeat this for any additional fields.
         */
        return $fields;
    }

    /**
     * When the form is submitted, update the data.
     *
     * All data is stored in the $values variable that is in the same
     * format as the fields array.
     *
     * @since Custom fields for WP Job Manager 1.0
     *
     * @param int $job_id The ID of the job being submitted.
     * @param array $values The values of each field.
     * @return void
     */
    function update_job_data($job_id, $values) {

        $featured_listing_upload = isset($values['company']['featured_listing_upload']) ? $values['company']['featured_listing_upload'] : null;

        if ($featured_listing_upload) {
            update_post_meta($job_id, '_featured_listing_upload', $featured_listing_upload);
        }
    }

    /**
     * Add fields to the admin write panel.
     *
     * There is a slight disconnect between the frontend and backend at the moment.
     * The frontend allows for select boxes, but there is no way to output those in
     * the admin panel at the moment.
     *
     * @since Custom fields for WP Job Manager 1.0
     *
     * @param array $fields The existing fields
     * @return array $fields The modified fields
     */
    function job_listing_data_fields($fields) {
        /**
         * Add the field we added to the frontend, using the meta key as the name of the
         * field. We do not need to separate these fields into "job" or "company" as they
         * are all output in the same spot.
         */
        $fields['_featured_listing_upload'] = array(
            'label' => 'Featured Listing Upload', // The label for the field
            'type' => 'file', // file, job-description (tinymce), select, text
            'placeholder' => '', // Placeholder value
            'required' => false, // If the field is required to submit the form
            'priority' => 10                 // Where should the field appear based on the others
        );
        /**
         * Repeat this for any additional fields.
         */
        return $fields;
    }

    /**
     * private validation functions
     */

    /**
     * Check to see if featured image is correct size
     * 
     * If the featured image being uploaded does not conform to size specs
     * return false.
     * 
     * NOTE: A better function would provide a filter to change the sizes as needed.
     * 
     * @param type $featured_image
     * @return BOOL
     */
    private function is_correct_image_size($featured_image) {
        list($width, $height, $type, $attr) = getimagesize($featured_image);

        // image cannot be less than 450 px wide
        if ($width < 450) {
            return false;
        }

        // image Height cannot exceed width
        if ($height > $width) {
            return false;
        }
        return true;
    }

    /**
     * Check to see if a job already has featured image
     * 
     * @param type $job_id
     * @return boolean
     */
    function has_featured_image($job_id) {
        $images = get_children(array(
            'post_parent' => $job_id,
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'numberposts' => 1
        ));

        $total_images = count($images);

        if ($total_images > 0) {
            return true;
        } else {
            return false;
        }
    }

}

add_action('init', array('Astoundify_Job_Manager_Fields', 'instance'));

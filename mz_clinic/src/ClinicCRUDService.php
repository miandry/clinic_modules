<?php

namespace Drupal\mz_clinic;

use Drupal\entity_parser\UtilityParser;

/**
 * Full CRUD service with typed-field handlers.
 */
class ClinicCRUDService extends ClinicCRUDBaseService
{
    public function __construct() {}

    public function paragraph($type, $fields, $reference_object = null)
    {
        return $this->save('paragraph', $type, $fields, $reference_object);
    }

    public function node($type, $fields, $reference_object = null)
    {
        return $this->save('node', $type, $fields, $reference_object);
    }

    public function string($entity_parent, $field_name, $field_value)
    {
        return $this->item_default($entity_parent, $field_name, $field_value);
    }

    public function float($entity_parent, $field_name, $field_value)
    {
        return $this->item_default($entity_parent, $field_name, $field_value);
    }

    public function image($entity_parent, $field_name, $field_value)
    {
        $field_images = [];
        if (is_string($field_value) && !is_numeric($field_value)) {
            $result = $this->saveImgFile($entity_parent, $field_name, $field_value);
            if ($result !== null) {
                $field_images[] = $result;
            }
        } elseif (is_array($field_value)) {
            foreach ($field_value as $image) {
                if (is_string($image) && !is_numeric($image)) {
                    $result = $this->saveImgFile($entity_parent, $field_name, $image);
                    if ($result !== null) {
                        $field_images[] = $result;
                    }
                } elseif (is_array($image)) {
                    // Prefer stream URI (same as EntityParser::image_file) so file_get_contents works.
                    $source = null;
                    if (!empty($image['uri']) && is_string($image['uri'])) {
                        $source = $image['uri'];
                    } elseif (!empty($image['url']) && is_string($image['url'])) {
                        $source = $image['url'];
                    }
                    if ($source) {
                        $result = $this->saveImgFile($entity_parent, $field_name, $source, $image);
                        if ($result !== null) {
                            $field_images[] = $result;
                        }
                    }
                }
            }
        }
        if (!empty($field_images)) {
            $entity_parent->set($field_name, $field_images);
        }
        return $entity_parent;
    }

    /**
     * Persists image bytes using entity_parser's UtilityParser (same rules as image_file).
     */
    public function saveImgFile($entity_parent, $field_image, $field_value, $array = [])
    {
        return UtilityParser::saveImageToField($entity_parent, $field_image, $field_value, $array);
    }

    public function entity_reference_revisions($entity_parent, $field_name, $field_value)
    {
        if (is_object($field_value)) {
            $entity_parent->set($field_name, $field_value);
        } elseif (is_numeric($field_value)) {
            $entity_parent->set($field_name, [
                ['target_id' => $field_value, 'target_revision_id' => $field_value],
            ]);
        } elseif (is_array($field_value) && !empty($field_value)) {
            $setting_field = $entity_parent->get($field_name)->getFieldDefinition()->getSettings();
            $bundle        = end($setting_field['handler_settings']['target_bundles']);
            $field_items   = [];
            foreach ($field_value as $item) {
                if (is_array($item)) {
                    $field_items[] = $this->save('paragraph', $bundle, $item);
                } elseif (is_numeric($item)) {
                    $field_items[] = ['target_id' => $item, 'target_revision_id' => $item];
                } elseif (is_object($item) && $item->id()) {
                    $field_items[] = ['target_id' => $item->id(), 'target_revision_id' => $item->getRevisionId()];
                }
            }
            $entity_parent->set($field_name, $field_items);
        }
        return $entity_parent;
    }

    public function entity_reference_media($entity_parent, $field_name, $field_value)
    {
        $setting_field = $entity_parent->get($field_name)->getFieldDefinition()->getSettings();
        $bundle        = end($setting_field['handler_settings']['target_bundles']);
        $key_label     = \Drupal::entityTypeManager()->getDefinition('media')->getKey('label');

        if (is_string($field_value) && !is_numeric($field_value)) {
            $filename = end(explode('/', $field_value));
            $media    = $this->save('media', $bundle, [$key_label => $filename, 'field_media_image' => $field_value]);
            $entity_parent->{$field_name}->entity = $media;
        } elseif (is_numeric($field_value)) {
            $entity_parent->{$field_name}->target_id = $field_value;
        } elseif (is_object($field_value)) {
            $entity_parent->{$field_name}->entity = $field_value;
        } elseif (is_array($field_value) && !empty($field_value)) {
            $field_items = [];
            foreach ($field_value as $item) {
                if (is_numeric($item)) {
                    $field_items[] = ['target_id' => $item];
                } elseif (is_object($item) && $item->id()) {
                    $field_items[] = ['target_id' => $item->id()];
                }
            }
            $entity_parent->set($field_name, $field_items);
        }
        return $entity_parent;
    }

    public function entity_reference_taxonomy_term($entity_parent, $field_name, $field_value)
    {
        return $this->entity_reference($entity_parent, $field_name, $field_value);
    }

    public function entity_reference_comment_type($entity_parent, $field_name, $field_value)
    {
        return $this->item_default($entity_parent, $field_name, $field_value);
    }

    public function entity_reference($entity_parent, $field_name, $field_value)
    {
        $setting_field = $entity_parent->get($field_name)->getFieldDefinition()->getSettings();
        $entity_type   = $setting_field['target_type'];
        $bundle        = ($setting_field['handler_settings'] && $setting_field['handler_settings']['target_bundles'])
            ? end($setting_field['handler_settings']['target_bundles'])
            : $entity_type;
        $fields_config = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type, $bundle);
        $field_list    = array_keys($fields_config);
        $key_label     = \Drupal::entityTypeManager()->getDefinition($entity_type)->getKey('label');

        if (is_string($field_value) && !is_numeric($field_value)) {
            $exists = $this->is_exits($entity_type, $bundle, $field_value);
            if (empty($exists)) {
                $term = $this->save($entity_type, $bundle, [$key_label => $field_value]);
                $entity_parent->{$field_name}->entity = $term;
            } else {
                $entity_parent->{$field_name}->target_id = end($exists);
            }
        } elseif (is_object($field_value)) {
            $entity_parent->{$field_name}->entity = $field_value;
        } elseif (is_numeric($field_value)) {
            $entity_parent->{$field_name}->target_id = $field_value;
        } elseif (is_array($field_value) && !empty($field_value)) {
            $field_items = [];
            foreach ($field_value as $item) {
                if (is_array($item)) {
                    $field_terms = array_intersect_key($item, array_flip($field_list));
                    if (!empty($field_terms)) {
                        $term_new = $this->save($entity_type, $bundle, $field_terms);
                        if (is_object($term_new) && $term_new->id()) {
                            $field_items[] = ['target_id' => $term_new->id()];
                        }
                    }
                } elseif (is_numeric($item)) {
                    $field_items[] = ['target_id' => $item];
                } elseif (is_object($item) && $item->id()) {
                    $field_items[] = ['target_id' => $item->id()];
                } elseif (is_string($item)) {
                    $exists = $this->is_exits($entity_type, $bundle, $item);
                    if (empty($exists)) {
                        $term          = $this->save($entity_type, $bundle, [$key_label => $item]);
                        $field_items[] = ['target_id' => $term->id()];
                    } else {
                        $field_items[] = ['target_id' => end($exists)];
                    }
                }
            }
            if (!empty($field_items)) {
                $entity_parent->set($field_name, $field_items);
            }
        }
        return $entity_parent;
    }

    public function entity_reference_user($entity_parent, $field_name, $field_value)
    {
        if (is_array($field_value)) {
            $field_required = [];
            $has_error      = false;
            foreach ($field_value as $item) {
                if (is_numeric($item)) {
                    $field_required[] = $item;
                } elseif (is_array($item) && isset($item['name'], $item['mail'], $item['pass'])) {
                    $field_required[] = $item;
                } else {
                    $has_error = true;
                }
            }
            if ($has_error) {
                \Drupal::messenger()->addMessage('Entity User require fields: name, mail, pass', 'error');
            }
            return !empty($field_required)
                ? $this->entity_reference($entity_parent, $field_name, $field_required)
                : $entity_parent;
        }
        return $this->entity_reference($entity_parent, $field_name, $field_value);
    }

    protected function is_exits($entity_type, $bundle, $value)
    {
        $key_label    = \Drupal::entityTypeManager()->getDefinition($entity_type)->getKey('label');
        $bundle_label = \Drupal::entityTypeManager()->getDefinition($entity_type)->getKey('bundle');
        return \Drupal::entityQuery($entity_type)
            ->condition($key_label, $value)
            ->condition($bundle_label, $bundle)
            ->range(0, 1)
            ->execute();
    }
}

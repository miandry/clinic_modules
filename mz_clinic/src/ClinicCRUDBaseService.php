<?php

namespace Drupal\mz_clinic;

/**
 * Base CRUD service — handles entity create/update with typed field dispatch.
 */
class ClinicCRUDBaseService implements ClinicCRUDInterface
{
    /**
     * Create or update an entity from a flat field array.
     * If $fields contains the entity id key, the existing entity is updated.
     */
    public function save($entity_type, $bundle, $fields, $reference_object = null)
    {
        $id_label  = \Drupal::entityTypeManager()->getDefinition($entity_type)->getKey('id');
        $key_label = \Drupal::entityTypeManager()->getDefinition($entity_type)->getKey('label');

        if ($reference_object === null) {
            $reference_object = $this;
        }

        if ($entity_type === 'comment') {
            $entity_new = $this->create_init_comment($entity_type, $bundle, $fields);
        } else {
            $entity_new = $this->create_init_entity($entity_type, $bundle);
        }

        if (!is_object($entity_new)) {
            return false;
        }

        $is_new = true;
        if (isset($fields[$id_label]) && is_numeric($fields[$id_label])) {
            $entity_new = \Drupal::entityTypeManager()->getStorage($entity_type)->load($fields[$id_label]);
            $is_new     = false;
        }

        if (!empty($fields)) {
            $keys = array_keys($fields);

            if (!in_array($id_label, $keys)) {
                $fields[$id_label] = null;
            }

            if ($is_new) {
                if ($key_label !== '' && !in_array($key_label, $keys)) {
                    $fields[$key_label] = $key_label . ' - EMPTY';
                }
            } else {
                if ($key_label !== '' && !in_array($key_label, $keys)) {
                    $fields[$key_label] = $entity_new ? $entity_new->label() : $key_label . ' - EMPTY';
                }
            }

            foreach ($fields as $key => $field) {
                if ($entity_new && $entity_new->hasField($key)) {
                    $status      = true;
                    $field_type  = $entity_new->get($key)->getFieldDefinition()->getType();
                    $setting_field = $entity_new->get($key)->getFieldDefinition()->getSettings();

                    if ($setting_field && isset($setting_field['target_type'])) {
                        $func = $field_type . '_' . $setting_field['target_type'];
                        if ($reference_object && method_exists($reference_object, $func)) {
                            $entity_new = $reference_object->{$func}($entity_new, $key, $field);
                            $status     = false;
                        } elseif ($reference_object && $field_type && method_exists($reference_object, $field_type)) {
                            $entity_new = $reference_object->{$field_type}($entity_new, $key, $field);
                            $status     = false;
                        }
                    } else {
                        if ($reference_object && $field_type && method_exists($reference_object, $field_type)) {
                            $entity_new = $reference_object->{$field_type}($entity_new, $key, $field);
                            $status     = false;
                        }
                    }

                    if ($status) {
                        $this->item_default($entity_new, $key, $field);
                    }
                }
            }
        }

        $status = $entity_new->save();
        return $status ? $entity_new : null;
    }

    public function create_init_comment($entity_type, $bundle, $fields)
    {
        if (isset($fields['entity_type'], $fields['entity_id'], $fields['field_name'], $fields['subject'], $fields['uid'])) {
            $fields['uid'] = \Drupal::currentUser()->id();
            if (!isset($fields['pid'])) {
                $fields['pid'] = null;
            }
            return \Drupal::entityTypeManager()->getStorage('comment')->create($fields);
        }

        \Drupal::messenger()->addMessage('Entity Comments require fields: entity_type, entity_id, field_name, subject, uid, comment_body', 'error');
        return false;
    }

    protected function create_init_entity($entity_type, $bundle, $id = null)
    {
        $entity_def = \Drupal::entityTypeManager()->getDefinition($entity_type);
        $uuid_key   = \Drupal::service('uuid')->generate();
        $key_label  = $entity_def->getKey('label');

        return \Drupal::entityTypeManager()->getStorage($entity_type)->create([
            $key_label                                      => 'crud empty',
            $entity_def->get('entity_keys')['bundle'] => $bundle,
            'uuid'                                          => $uuid_key,
        ]);
    }

    public function item_default($entity_parent, $field_name, $field_value)
    {
        $entity_parent->set($field_name, $field_value);
        return $entity_parent;
    }

    public function delete($entity_type, $bundle, $id)
    {
        $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($id);
        if ($entity) {
            $entity->delete();
            return true;
        }
        return false;
    }
}

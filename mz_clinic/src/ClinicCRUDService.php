<?php

namespace Drupal\mz_clinic;

use Drupal\Core\File\FileSystemInterface;

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
                    $image_url = isset($image['uri']) ? file_create_url($image['uri']) : ($image['url'] ?? null);
                    if ($image_url) {
                        $result = $this->saveImgFile($entity_parent, $field_name, $image_url, $image);
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

    public function saveImgFile($entity_parent, $field_image, $field_value, $array = [])
    {
        $file_system = \Drupal::service('file_system');
        $logger      = \Drupal::logger('mz_clinic');

        if (is_string($field_value) && strpos($field_value, 'data:image/') === 0) {
            if (!preg_match('/^data:image\/(\w+);base64,/i', $field_value, $matches)) {
                $logger->error('saveImgFile: format base64 invalide — @start', [
                    '@start' => substr($field_value, 0, 60),
                ]);
                return null;
            }
            $extension  = strtolower($matches[1]);
            $base64Part = substr($field_value, strpos($field_value, ',') + 1);
            $data       = base64_decode($base64Part, true);
            if ($data === false || strlen($data) === 0) {
                $logger->error('saveImgFile: base64_decode échoué (longueur entrée: @len)', [
                    '@len' => strlen($base64Part),
                ]);
                return null;
            }
            $filename = 'img_' . uniqid() . '.' . $extension;
        } else {
            $parts    = explode('/', $field_value);
            $filename = end($parts) ?: ('img_' . uniqid());
            $data     = @file_get_contents($field_value);
            if ($data === false || strlen($data) === 0) {
                $logger->error('saveImgFile: lecture fichier distant échouée @url', ['@url' => $field_value]);
                return null;
            }
        }

        $setting        = $entity_parent->get($field_image)->getSettings();
        $file_directory = !empty($setting['file_directory']) ? $setting['file_directory'] : 'articles';
        $path_root      = 'public://' . $file_directory . '/';
        $path_root      = \Drupal::token()->replace($path_root);

        $logger->info('saveImgFile: sauvegarde dans @path (fichier: @file, @size octets)', [
            '@path' => $path_root,
            '@file' => $filename,
            '@size' => strlen($data),
        ]);

        if (!$file_system->prepareDirectory($path_root, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
            $logger->error('saveImgFile: impossible de préparer le répertoire @path', ['@path' => $path_root]);
            return null;
        }

        $file = file_save_data($data, $path_root . $filename, FileSystemInterface::EXISTS_RENAME);
        if (!$file) {
            $logger->error('saveImgFile: file_save_data échoué pour @path@file', [
                '@path' => $path_root,
                '@file' => $filename,
            ]);
            return null;
        }

        $file->setPermanent();
        $file->save();
        $logger->info('saveImgFile: succès fid=@fid', ['@fid' => $file->id()]);

        return [
            'target_id' => $file->id(),
            'alt'       => $array['alt'] ?? '',
            'title'     => $array['title'] ?? '',
        ];
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

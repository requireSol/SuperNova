<?php

function qst_render_page() {
  global $user, $template;

  $user_id = sys_get_param_id('user_id', false);
  $mode = sys_get_param_str('mode');

  $quest_units_allowed = sn_get_groups(array('structures', 'tech', 'fleet', 'defense'));
  $quest_reward_allowed = sn_get_groups('quest_rewards');

  $in_admin = defined('IN_ADMIN') && IN_ADMIN === true;

  if ($in_admin) {
    $quest_id = sys_get_param_id('id');
    $quest_name = sys_get_param_str_unsafe('QUEST_NAME');
    if (!empty($quest_name)) {
      $quest_description = sys_get_param_str_unsafe('QUEST_DESCRIPTION');
      try {
        $quest_rewards_list = sys_get_param('QUEST_REWARDS_LIST');
        $quest_rewards = array();
        foreach ($quest_rewards_list as $quest_rewards_id => $quest_rewards_amount) {
          if (!in_array($quest_rewards_id, $quest_reward_allowed)) {
            throw new Exception(classLocale::$lang['qst_adm_err_reward_type']);
          }

          if ($quest_rewards_amount < 0) {
            throw new Exception(classLocale::$lang['qst_adm_err_reward_amount']);
          } elseif ($quest_rewards_amount > 0) {
            $quest_rewards[] = "{$quest_rewards_id},{$quest_rewards_amount}";
          }
        }
        if (empty($quest_rewards)) {
          throw new Exception(classLocale::$lang['qst_adm_err_reward_empty']);
        }

        $quest_rewards = implode(';', $quest_rewards);

        $quest_unit_id = sys_get_param_int('QUEST_UNIT_ID');
        if (!in_array($quest_unit_id, $quest_units_allowed)) {
          throw new Exception(classLocale::$lang['qst_adm_err_unit_id']);
        }

        $quest_unit_amount = sys_get_param_float('QUEST_UNIT_AMOUNT');
        if ($quest_unit_amount <= 0) {
          throw new Exception(classLocale::$lang['qst_adm_err_unit_amount']);
        }
        $quest_conditions = "{$quest_unit_id},{$quest_unit_amount}";

        // TODO: Change quest type
        $quest_type = 0;

        if ($mode == 'edit') {
          $quest_name_unsafe = $quest_name;
          $quest_description_unsafe = $quest_description;
          db_quest_update($quest_name_unsafe, $quest_type, $quest_description_unsafe, $quest_conditions, $quest_rewards, $quest_id);
        } else {
          sn_db_perform('{{quest}}', array(
            'quest_name'        => $quest_name,
            'quest_type'        => $quest_type,
            'quest_description' => $quest_description,
            'quest_conditions'  => $quest_conditions,
            'quest_rewards'     => $quest_rewards,
          ));
        }

        // TODO: Add mass mail for new quests
      } catch (Exception $e) {
        message($e->getMessage(), classLocale::$lang['sys_error']);
      }

      $mode = '';
    };

    switch ($mode) {
      case 'del':
        db_quest_delete($quest_id);
        $mode = '';
      break;

      case 'edit':
        $template->assign_var('QUEST_ID', $quest_id);

      case 'copy':
        $quest = db_quest_get($quest_id);
      break;
    }
    $query = db_quest_count();
    classSupernova::$config->db_saveItem('quest_total', $query['count']);
  } elseif (!$user_id) {
    $user_id = $user['id'];
  }

  $quest_list = qst_get_quests($user_id);
  $template->assign_vars(array(
    'AUTHLEVEL' => $user['authlevel'],
    'TOTAL'     => count($quest_list),
    'mode'      => $mode,
    'USER_ID'   => $user_id,
    'IN_ADMIN'  => $in_admin,
  ));

  if (!empty($quest)) {
    $quest_templatized = qst_templatize(qst_quest_parse($quest));
  } else {
    $quest_templatized['quest_rewards_list'] = array();
  }

  foreach ($quest_reward_allowed as $unit_id) {
    $found = false;
    foreach ($quest_templatized['quest_rewards_list'] as $quest_templatized_reward) {
      if ($quest_templatized_reward['ID'] == $unit_id) {
        $found = true;
        break;
      }
    }

    if (!$found) {
      $quest_templatized['quest_rewards_list'][$unit_id] = array(
        'ID'     => $unit_id,
        'NAME'   => classLocale::$lang['tech'][$unit_id],
        'AMOUNT' => 0,
      );
    }
  }

  qst_assign_to_template($template, $quest_templatized);

  foreach ($quest_list as $quest_data) {
    qst_assign_to_template($template, qst_templatize($quest_data, true), 'quest');
  }

  foreach ($quest_units_allowed as $unit_id) {
    $template->assign_block_vars('allowed_unit', array(
      'ID'   => $unit_id,
      'NAME' => classLocale::$lang['tech'][$unit_id],
    ));
  }
}

function qst_get_quests($user_id = false, $status = false) {
  $quest_list = array();

  $query_add_select = '';
  $query_add_from = '';
  $query_add_where = '';

  if ($user_id) {
    if ($status !== false) {
      $query_add_where = "AND qs.quest_status_status ";
      if ($status == null) {
        $query_add_where .= "IS NULL";
      } else {
        $query_add_where .= "= {$status}";
      }
    }
    $query_add_select = ", qs.quest_status_progress, qs.quest_status_status";
    $query_add_from = "LEFT JOIN {{quest_status}} AS qs ON qs.quest_status_quest_id = q.quest_id AND qs.quest_status_user_id = {$user_id}";
  }

  $query = db_quest_list_get($query_add_select, $query_add_from, $query_add_where);

  while ($quest = db_fetch($query)) {
    $quest_list[$quest['quest_id']] = qst_quest_parse($quest);
  }

  return $quest_list;
}

/**
 * @param template $template
 * @param          $quest_templatized
 * @param bool     $block_name
 */
function qst_assign_to_template(&$template, $quest_templatized, $block_name = false) {
  if ($block_name) {
    $template->assign_block_vars($block_name, $quest_templatized);
  } else {
    $template->assign_vars($quest_templatized);
    if (!empty($quest_templatized['quest_rewards_list'])) {
      foreach ($quest_templatized['quest_rewards_list'] as $quest_reward) {
        $template->assign_block_vars(($block_name ? $block_name . '.' : '') . 'quest_rewards_list', $quest_reward);
      }
    }
  }
}

function qst_quest_parse($quest) {
  list($quest['quest_unit_id'], $quest['quest_unit_amount']) = explode(',', $quest['quest_conditions']);

  $quest['quest_rewards_list'] = sys_unit_str2arr($quest['quest_rewards']);

  return $quest;
}

function qst_templatize($quest, $for_display = true) {
  $tmp = array();
  foreach ($quest['quest_rewards_list'] as $quest_reward_id => $quest_reward_amount) {
    $tmp[] = array(
      'ID'     => $quest_reward_id,
      'NAME'   => $for_display ? str_replace(' ', '&nbsp;', classLocale::$lang['tech'][$quest_reward_id]) : classLocale::$lang['tech'][$quest_reward_id],
      'AMOUNT' => $quest_reward_amount,
    );
  }

  return array(
    'QUEST_ID'           => $quest['quest_id'],
    'QUEST_NAME'         => $quest['quest_name'],
    'QUEST_TYPE'         => $quest['quest_type'],
    'QUEST_DESCRIPTION'  => $for_display ? sys_bbcodeParse($quest['quest_description']) : $quest['quest_description'],
    'QUEST_CONDITIONS'   => $quest['quest_condition'],
    'QUEST_UNIT_ID'      => $quest['quest_unit_id'],
    'QUEST_UNIT_NAME'    => classLocale::$lang['tech'][$quest['quest_unit_id']],
    'QUEST_UNIT_AMOUNT'  => $quest['quest_unit_amount'],
    'QUEST_STATUS'       => intval($quest['quest_status_status']),
    'QUEST_STATUS_NAME'  => classLocale::$lang['qst_status_list'][intval($quest['quest_status_status'])],
    'quest_rewards_list' => $tmp,
  );
}

function qst_active_triggers($quest_list) {
  $quest_triggers = array();
  foreach ($quest_list as $quest_id => $quest) {
    if ($quest['quest_status_status'] != QUEST_STATUS_COMPLETE) {
      list($quest_unit_id, $quest_unit_amount) = explode(',', $quest['quest_conditions']);
      $quest_triggers[$quest_id] = $quest_unit_id;
    }
  }

  return $quest_triggers;
}

function qst_reward(&$user, &$rewards, &$quest_list) {
  if (empty($rewards)) {
    return;
  }

  $classLocale = classLocale::$lang;

  $total_rewards = array();
  $comment_dm = '';

  foreach ($rewards as $quest_id => $user_data) {
    foreach ($user_data as $user_id => $planet_data) {
      foreach ($planet_data as $planet_id => $reward_list) {
        $comment = sprintf(classLocale::$lang['qst_msg_complete_body'], $quest_list[$quest_id]['quest_name']);
        $comment_dm .= isset($reward_list[RES_DARK_MATTER]) ? $comment : '';

        $comment_reward = array();
        foreach ($reward_list as $unit_id => $unit_amount) {
          $comment_reward[] = $unit_amount . ' ' . classLocale::$lang['tech'][$unit_id];
          $total_rewards[$user_id][$planet_id][$unit_id] += $unit_amount;
        }
        $comment .= " {$classLocale['qst_msg_your_reward']} " . implode(',', $comment_reward);

        DBStaticMessages::msg_send_simple_message($user['id'], 0, SN_TIME_NOW, MSG_TYPE_ADMIN, classLocale::$lang['msg_from_admin'], classLocale::$lang['qst_msg_complete_subject'], $comment);

        sn_db_perform('{{quest_status}}', array(
          'quest_status_quest_id' => $quest_id,
          'quest_status_user_id'  => $user_id,
          'quest_status_status'   => QUEST_STATUS_COMPLETE
        ));
      }
    }
  }

  $group_resources = sn_get_groups('resources_loot');
  $quest_rewards_allowed = sn_get_groups('quest_rewards');
  if (!empty($total_rewards)) {
    foreach ($total_rewards as $user_id => $planet_data) {
      $user_row = classSupernova::$gc->cacheOperator->db_get_record_by_id(LOC_USER, $user_id);
      foreach ($planet_data as $planet_id => $unit_data) {
        $resourcesChange = array();
        foreach ($unit_data as $unit_id => $unit_amount) {
          if (!isset($quest_rewards_allowed[$unit_id])) {
            continue;
          }

          if ($unit_id == RES_DARK_MATTER) {
            rpg_points_change($user['id'], RPG_QUEST, $unit_amount, $comment_dm);
          } elseif (isset($group_resources[$unit_id])) {
            $resourcesChange[$unit_id] = $unit_amount;
          } else // Проверим на юниты
          {
            DBStaticUnit::dbUpdateOrInsertUnit($unit_id, $unit_amount, $user_row, $planet_id);
          }
          // unit
        }

        if (!empty($resourcesChange)) {
          $planet_id = $planet_id == 0 && isset($user_row['id_planet']) ? $user_row['id_planet'] : $planet_id;
          if($planet_id) {
            // update planet
            DBStaticPlanet::db_planet_update_resources($resourcesChange, $planet_id);
          } else {
            // update user
            DBStaticUser::db_user_update_resources($resourcesChange, $planet_id);
          }
        }
      }
    }
  }
}

function get_quest_amount_complete($user_id) {
  // TODO: Make it faster - rewrite SQL?
  return count(qst_get_quests($user_id, QUEST_STATUS_COMPLETE));
}

// TODO: Move here quest comlpletion checks
// TODO: Check mutiply condition quests
/*
function qst_check_completion(&$user, &$planet, )
{
}
*/

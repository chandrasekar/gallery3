<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2010 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class rest_Core {
  const API_VERSION = "3.0";

  static function reply($data=array()) {
    Session::instance()->abort_save();

    header("X-Gallery-API-Version: " . rest::API_VERSION);
    if (Input::instance()->get("output") == "html") {
      header("Content-type: text/html");
      if ($data) {
        $html = preg_replace(
          "#([\w]+?://[\w]+[^ \'\"\n\r\t<]*)#ise", "'<a href=\"\\1\" >\\1</a>'",
          var_export($data, 1));
      } else {
        $html = t("Empty response");
      }
      print "<pre>$html</pre>";
    } else {
      header("Content-type: application/json");
      print json_encode($data);
    }
  }

  static function set_active_user($access_key) {
    if (empty($access_key)) {
      if (module::get_var("rest", "allow_guest_access")) {
        identity::set_active_user(identity::guest());
        return;
      } else {
        throw new Rest_Exception("Forbidden", 403);
      }
    }

    $key = ORM::factory("user_access_key")
      ->where("access_key", "=", $access_key)
      ->find();

    if (!$key->loaded()) {
      throw new Rest_Exception("Forbidden", 403);
    }

    $user = identity::lookup_user($key->user_id);
    if (empty($user)) {
      throw new Rest_Exception("Forbidden", 403);
    }

    identity::set_active_user($user);
  }

  static function access_key() {
    $key = ORM::factory("user_access_key")
      ->where("user_id", "=", identity::active_user()->id)
      ->find();

    if (!$key->loaded()) {
      $key->user_id = identity::active_user()->id;
      $key->access_key = md5(md5(uniqid(mt_rand(), true) . access::private_key()));
      $key->save();
    }

    return $key->access_key;
  }

  /**
   * Convert a REST url into an object.
   * Eg:
   *   http://example.com/gallery3/index.php/rest/item/35          -> Item_Model
   *   http://example.com/gallery3/index.php/rest/tag/16           -> Tag_Model
   *   http://example.com/gallery3/index.php/rest/tagged_item/1,16 -> [Tag_Model, Item_Model]
   *
   * @param string  the fully qualified REST url
   * @return mixed  the corresponding object (usually a model of some kind)
   */
  static function resolve($url) {
    $relative_url = substr($url, strlen(url::abs_site("rest")));
    $path = parse_url($relative_url, PHP_URL_PATH);
    $components = explode("/", $path, 3);

    if (count($components) != 3) {
      throw new Kohana_404_Exception($url);
    }

    $class = "$components[1]_rest";
    if (!method_exists($class, "resolve")) {
      throw new Kohana_404_Exception($url);
    }

    return call_user_func(array($class, "resolve"), !empty($components[2]) ? $components[2] : null);
  }

  /**
   * Return an absolute url used for REST resource location.
   * @param  string  resource type (eg, "item", "tag")
   * @param  object  resource
   */
  static function url() {
    $args = func_get_args();
    $resource_type = array_shift($args);

    $class = "{$resource_type}_rest";
    if (!method_exists($class, "url")) {
      throw new Rest_Exception("Bad Request", 400);
    }

    $url = call_user_func_array(array($class, "url"), $args);
    if (Input::instance()->get("output") == "html") {
      if (strpos($url, "?") === false) {
        $url .= "?output=html";
      } else {
        $url .= "&output=html";
      }
    }
    return $url;
  }

  static function relationships($resource_type, $resource) {
    $results = array();
    foreach (module::active() as $module) {
      foreach (glob(MODPATH . "{$module->name}/helpers/*_rest.php") as $filename) {
        $class = str_replace(".php", "", basename($filename));
        if (method_exists($class, "relationships")) {
          if ($tmp = call_user_func(array($class, "relationships"), $resource_type, $resource)) {
            $results = array_merge($results, $tmp);
          }
        }
      }
    }

    return $results;
  }
}

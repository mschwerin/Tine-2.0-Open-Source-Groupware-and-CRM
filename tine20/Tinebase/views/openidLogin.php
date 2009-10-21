<?php
/**
 * OpenId login screen
 * 
 * @package     Tinebase
 * @subpackage  Views
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Lars Kneschke <l.kneschke@metaways.de>
 * @copyright   Copyright (c) 2009 Metaways Infosystems GmbH (http://www.metaways.de)
 * @version     $Id$
 */
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    
        <link rel="icon" href="images/favicon.ico" type="image/x-icon" />
        <link rel="stylesheet" type="text/css" href="styles/tine20.css" />
    </head>
    <body>
        <form method="post">
            <fieldset>
                <legend>OpenID Login</legend>
                <table border=0>
                    <tr>
                        <td>OpenID:</td>
                        <td>
                            <?php echo $this->escape($this->openIdIdentity);?>
                        </td>
                    </tr>
                    <tr>
                        <td>Loginname:</td>
                        <td>
                            <input type="text"
                                   name="openid_identifier"
                                   size="50"
                                   value="<?php echo $this->escape($this->loginName);?>">
                        </td>
                    </tr>
                    <tr>
                        <td>Password:</td>
                        <td>
                            <input type="password" name="openid_password" value="">
                        </td>
                    </tr>
                    <tr>
                        <td>&nbsp;</td>
                        <td>
                            <input type="hidden" name="openid_action" value="login">
                            <input type="submit" value="Login">
                        </td>
                    </tr>
                </table>
            </fieldset>
        </form>
    </body>
</html>

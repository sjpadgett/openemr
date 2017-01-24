<?php
/**
 *
 * Copyright (C) 2016-2017 Jerry Padgett <sjpadgett@gmail.com>
 *
 * LICENSE: This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package OpenEMR
 * @author Jerry Padgett <sjpadgett@gmail.com>
 * @link http://www.open-emr.org
 */
?>
<head>
<meta charset="utf-8">

<title><?php $this->eprint($this->title); ?></title>
<meta content="width=device-width, initial-scale=1, user-scalable=no"	name="viewport">

<base href="<?php $this->eprint($this->ROOT_URL); ?>" />
<meta name="description" content="Patient Profile" />
<meta name="author" content="Form | sjpadgett@gmail.com" />

<!-- <link href="../assets/bootstrap/css/bootstrap.min12px.css" rel="stylesheet" />
<link href="../assets/bootstrap/css/bootstrap-theme.min.css" rel="stylesheet" />-->
<link href="<?php echo $GLOBALS['assets_static_relative']; ?>/font-awesome-4-6-3/css/font-awesome.min.css" rel="stylesheet" />
<link href="<?php echo $GLOBALS['assets_static_relative']; ?>/eonasdan-bootstrap-datetimepicker-3-1-3/build/css/bootstrap-datetimepicker.min.css"	rel="stylesheet" />
<link href="<?php echo $GLOBALS['assets_static_relative']; ?>/bootstrap-combobox-1-1-7/css/bootstrap-combobox.css" rel="stylesheet" />

<!--<script type="text/javascript" src="<?php echo $GLOBALS['assets_static_relative']; ?>/jquery-min-1-11-3/index.js"></script>
 <script type="text/javascript" src="../assets/bootstrap/js/bootstrap.min12px.js"></script>-->
<link href="styles/style.css" rel="stylesheet" />
<script type="text/javascript" src="<?php echo $GLOBALS['assets_static_relative']; ?>/moment-2-13-0/moment.js"></script>
<script type="text/javascript" src="<?php echo $GLOBALS['assets_static_relative']; ?>/bootstrap-combobox-1-1-7/js/bootstrap-combobox.js"></script>
<script type="text/javascript" src="<?php echo $GLOBALS['assets_static_relative']; ?>/eonasdan-bootstrap-datetimepicker-3-1-3/build/js/bootstrap-datetimepicker.min.js"></script>
<script type="text/javascript" src="<?php echo $GLOBALS['assets_static_relative']; ?>/underscore-1-8-3/underscore-min.js"></script>
<script type="text/javascript" src="<?php echo $GLOBALS['assets_static_relative']; ?>/backbone-1-3-3/backbone-min.js"></script>
 <!--<script type="text/javascript" src="<?php echo $GLOBALS['assets_static_relative']; ?>/emodal-1-2-65/dist/eModal.js?version=&&TIME&&"></script>-->
<script type="text/javascript" src="scripts/app.js"></script>
<script type="text/javascript" src="scripts/model.js"></script>
<script type="text/javascript" src="scripts/view.js"></script>

<script type="text/javascript" src="scripts/libs/LAB.min.js"></script>
<script type="text/javascript">
$LAB.setGlobalDefaults({BasePath: "<?php $this->eprint($this->ROOT_URL); ?>"});
</script>
<!-- * Don't need script load order or waits here ???
<script type="text/javascript">
			$LAB.script("<?php echo $GLOBALS['assets_static_relative']; ?>/jquery-min-1-11-3/index.js").wait()
				.script("assets/bootstrap/js/bootstrap.min.js")
				.script("<?php echo $GLOBALS['assets_static_relative']; ?>/moment-2-13-0/moment.js")
				.script("assets/bootstrap/js/bootstrap-datepicker.js")
				.script("assets/bootstrap/js/bootstrap-timepicker.min.js")
				.script("<?php echo $GLOBALS['assets_static_relative']; ?>/bootstrap-combobox-1-1-7/js/bootstrap-combobox.js")
				.script("<?php echo $GLOBALS['assets_static_relative']; ?>/eonasdan-bootstrap-datetimepicker-3-1-3/build/js/bootstrap-datetimepicker.min.js")
				.script("<?php echo $GLOBALS['assets_static_relative']; ?>/underscore-1-8-3/underscore-min.js").wait()
				.script("<?php echo $GLOBALS['assets_static_relative']; ?>/backbone-1-3-3/backbone-min.js")
				.script("patient/scripts/app.js")
				.script("patient/scripts/model.js").wait()
				.script("patient/scripts/view.js").wait()
		</script> -->

</head>
<body>

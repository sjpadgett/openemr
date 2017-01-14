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
//$ignoreAuth = true;
$sanitize_all_escapes=true;
$fake_register_globals=false;
require_once("../interface/globals.php");

$tdir = $GLOBALS['fileroot'] . "/patients/patient_documents/templates/";
function getTemplateList($dir){
	//$finfo = finfo_open(FILEINFO_MIME_TYPE);
	$retval = array();
	if(substr($dir, -1) != "/") $dir .= "/";
	$d = @dir($dir) or die("File List: Failed opening directory $dir for reading");
	while(false !== ($entry = $d->read())) {
		if($entry[0] == "." || substr($entry,-3) != 'tpl') continue;
		
		if(is_dir("$dir$entry")) {
			$retval[] = array(
					'pathname' => "$dir$entry",
					'name' => "$entry",
					//'type' => filetype("$dir$entry"),
					'size' => 0,
					'lastmod' => filemtime("$dir$entry")
			);
		} elseif(is_readable("$dir$entry")) {
			$retval[] = array(
					'pathname' => "$dir$entry",
					'name' => "$entry",
					//'type' => ($finfo) ? finfo_file($finfo, "$dir$entry") : mime_content_type("$dir$entry"),
					'size' => filesize("$dir$entry"),
					'lastmod' => filemtime("$dir$entry")
			);
		}
	}
	$d->close();
	return $retval;
}

?>
<html>
<head>
<meta charset="UTF-8">
<title><?php echo xlt('OpenEMR Portal'); ?> | <?php echo xlt('Import'); ?></title>
<meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>
<meta name="description" content="Developed By sjpadgett@gmail.com">

<link href="assets/bootstrap/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
<link href="assets/bootstrap/css/bootstrap.min.css" rel="stylesheet" type="text/css" />
<link href="assets/css/style.css" rel="stylesheet" type="text/css" />
<script src="assets/js/jquery-1.11.3.min.js" type="text/javascript"></script>
<script src="assets/bootstrap/js/bootstrap.min.js" type="text/javascript"></script>
<link rel="stylesheet" href="<?php echo $GLOBALS['assets_static_relative']; ?>/summernote-0-8-2/dist/summernote.css" />
<script type='text/javascript' src="<?php echo $GLOBALS['assets_static_relative']; ?>/summernote-0-8-2/dist/summernote.js"></script>
</head>
<script>
var currentEdit = "";
var tedit = function(docname) {
	currentEdit = docname;
	getDocument(docname, 'get', '')
	return false;
	};

var tsave = function() {
	var makrup = $('#templatecontent').summernote('code');
	getDocument(currentEdit, 'save', makrup)	  
	};
	
var tdelete = function(docname) {
	var delok = confirm("You are about to delete template: "+docname+"\nIs this Okay?");
	if(delok === true) getDocument(docname, 'delete', '')
	return false;
	};	
 function getDocument(docname, mode, content){
		var liburl = 'import_template.php';
			$.ajax({
				type: "POST",
				url: liburl,
				data: {docid: docname, mode: mode,content: content},
				beforeSend: function(xhr){
					console.log("Please wait..."+content);
				},
				error: function(qXHR, textStatus, errorThrow){
					console.log("There was an error");
				},
				success: function(templateHtml, textStatus, jqXHR){
					if(mode == 'get'){
						//console.log("File get..."+templateHtml);
						$('#templatecontent').summernote('destroy');
						$('#templatecontent').empty().append(templateHtml);
						$('#popeditor').modal('show');
						$('#templatecontent').summernote({focus: true});
						}
					else if(mode == 'save'){
						$('#templatecontent').summernote('destroy');
						location.reload();
					}
					else if(mode == 'delete'){
						location.reload();
					}
				}
			});
	}
</script>
<style>

</style>
<body class="skin-blue">
<div  class='container' style='display:block;'>
<hr>
<h3><?php echo xlt('Patient Document Template Upload'); ?></h3>
<h4><em><?php echo xlt('File base name becomes Menu selection'); ?>.<br><?php echo xlt('Automatically applies correct extension on successfull upload'); ?>.<br> 
<?php echo xlt('Example Privacy_Agreement.txt becomes Privacy Agreement button in Patient Documents'); ?>.</em></h4>
<form class="form" action="import_template.php" method="post" enctype="multipart/form-data">
<input class="btn btn-info" type="file" name="tplFile">
<br>
<button class="btn btn-primary" type="button" onclick="location.href='./patient/provider'"><?php echo xl('Home'); ?></button>
<input class="btn btn-success" type="submit" value="Upload">
</form>

<div class='col col-md col-lg'>
<h3><?php echo xlt('Active Templates'); ?></h3>
<?PHP
$dirlist = getTemplateList($tdir);
  echo "<table  class='table table-striped table-bordered'>";
  echo "<thead>";
  echo "<tr><th>" . xl("Template") . " - <i>" . xl("Click to edit") . "</i></th><th>" . xl("Size") . "</th><th>" . xl("Last Modified") . "</th></tr>";
  echo "</thead>";
  echo "<tbody>";
  foreach($dirlist as $file) {
  	$t = "'".$file['pathname']."'";
  	echo "<tr>";
    echo '<td><button id="tedit" class="btn btn-sm btn-primary" onclick="tedit('.$t.')" type="button">'. $file['name'].'</button>
 		<button id="tdelete" class="btn btn-xs btn-danger" onclick="tdelete('.$t.')" type="button">'.  xl("Delete") .'</button></td>';
    echo "<td>{$file['size']}</td>";
    echo "<td>",date('r', $file['lastmod']),"</td>";
    echo "</tr>";
  }
  echo "</tbody>";
  echo "</table>";
?>
</div>
</div>

   <div class="modal fade" id="popeditor">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">
                            <span aria-hidden="true">&times;</span>
                            <span class="sr-only"><?php echo xl('Close'); ?></span>
                        </button>
                        <h4 class="modal-title"><?php echo xl('Edit Template'); ?></h4>
                    </div>
                    <div class="modal-body">
                        <div class="edittpl" id="templatecontent"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger btn-sm" data-dismiss="modal"><?php echo xl('Dismiss'); ?></button>
                        <button type="button" class="btn btn-success" data-dismiss="modal" onclick="tsave()"><?php echo xl('Save'); ?></button>
                    </div>                
            </div>
        </div>
    </div>
    </body>
</html>
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use DB;
use ZipArchive;

class DatabaseBackupController extends Controller
{
    private $gClient;
    function __construct()
    {
        $this->gClient = new \Google_Client();
        $this->gClient->setApplicationName(env('GOOGLE_DRIVE_CLIENT_NAME')); // ADD YOUR AUTH2 APPLICATION NAME (WHEN YOUR GENERATE SECRATE KEY)
        $this->gClient->setClientId(env('GOOGLE_DRIVE_CLIENT_ID'));
        $this->gClient->setClientSecret(env('GOOGLE_DRIVE_CLIENT_SECRET'));
        $this->gClient->setDeveloperKey(env('GOOGLE_DRIVE_CLIENT_API_KEY'));
        $this->gClient->setScopes(array(
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/drive'
        ));
        $this->gClient->setAccessType("offline");
        $this->gClient->setApprovalPrompt("force");
    }

    private function _token()
    {
        $client_id = config('services.google.client_id');
        $client_secret = \config('services.google.client_secret');
        $refresh_token = \config('services.google.refresh_token');
        $response = Http::post('https://oauth2.googleapis.com/token', [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token'
        ]);
        return json_decode((string)$response->getBody(), true)['access_token'] ?? '';
    }

    public function takeBackUp()
    {
        $created_db_name = $this->_create_db_backup();
        $file_Uploaded = $this->_uploadDbToDrive($created_db_name);
        dd($file_Uploaded);
    }

    private function _uploadDbToDrive($file_name)
    {
        $token = $this->_token();
        $documentPath = storage_path('app\zip');
        $filesInFolder = File::allFiles($documentPath);
        $file_uploaded = false;
        foreach ($filesInFolder as $k => $file) {
            $fileContent = file_get_contents($file->getPathname());
            $service = new \Google_Service_Drive($this->gClient);
            $this->gClient->setAccessToken($token);
            $file = new \Google_Service_Drive_DriveFile(array('name' => $file_name, 'parents' => array(env('GOOGLE_DRIVE_FOLDER_ID'))));

            $result = $service->files->create($file, array(
                'data' => $fileContent,
                'mimeType' => 'application/octet-stream',
                'uploadType' => 'media'
            ));
            if ($result) {
                $file_uploaded = true;
            }
        }

        if ($file_uploaded === true) {
            $path = "app/zip/";
            $isFileDelete = $this->__delete_db_file($file_name, $path);
            if ($isFileDelete) {
                return response()->json(['success' => true, 'status' => 200, 'message' => 'Database Backup successfully']);
            }
        }
    }

    private function _create_db_backup()
    {
        $DbName             = env('DB_DATABASE');
        $get_all_table_query = "SHOW TABLES ";
        $result = DB::select(DB::raw($get_all_table_query));

        $prep = "Tables_in_$DbName";
        foreach ($result as $res) {
            $tables[] =  $res->$prep;
        }
        $connect = DB::connection()->getPdo();

        $get_all_table_query = "SHOW TABLES";
        $statement = $connect->prepare($get_all_table_query);
        $statement->execute();
        $result = $statement->fetchAll();

        $output = '';
        foreach ($tables as $table) {
            $show_table_query = "SHOW CREATE TABLE " . $table . "";
            $statement = $connect->prepare($show_table_query);
            $statement->execute();
            $show_table_result = $statement->fetchAll();

            foreach ($show_table_result as $show_table_row) {
                $output .= "\nDROP TABLE IF EXISTS `" . $show_table_row['Table'] . "`;";
                $output .= "\n" . $show_table_row["Create Table"] . ";\n\n";
            }
            $select_query = "SELECT * FROM " . $table . "";
            $statement = $connect->prepare($select_query);
            $statement->execute();
            $total_row = $statement->rowCount();

            for ($count = 0; $count < $total_row; $count++) {
                $single_result = $statement->fetch(\PDO::FETCH_ASSOC);
                $table_column_array = array_keys($single_result);
                $table_value_array = array_values($single_result);
                $output .= "INSERT INTO $table (";
                $output .= "" . implode(", ", $table_column_array) . ") VALUES (";
                $output .= "'" . implode("','", $table_value_array) . "');\n";
            }
        }
        $file_name = 'database_backup_on_' . date('y-m-d') . '.sql';
        if (!file_exists(base_path('storage/app/backup'))) {
            mkdir(base_path('storage/app/backup'), 0777, true);
        }

        $destinationPath = 'backup/' . $file_name;
        Storage::disk('local')->put($destinationPath, $output);

        $zipped_file = $this->__create_zip_file($file_name, 'app/backup/');

        ob_clean();
        flush();
        return $zipped_file;
    }
    /**
     * Wrap MySQl File in Zip format for compress File size
     */
    private function __create_zip_file($file_name, $path)
    {
        $zip = new ZipArchive();
        $zip_file_path = 'app/zip/';
        $zipFileName = str_replace('.sql', '.zip', $file_name);

        if (!file_exists(base_path('storage/app/zip'))) {
            mkdir(base_path('storage/app/zip'), 0777, true);
        }

        if ($zip->open(storage_path($zip_file_path . $zipFileName), ZipArchive::CREATE) === TRUE) {
            $filesToZip = [
                storage_path($path . $file_name),
            ];

            foreach ($filesToZip as $file) {
                $zip->addFile($file, basename($file));
            }
            $zip->close();
            $path = "app/backup/";
            $this->__delete_db_file($file_name, $path);
            return $zipFileName;
        } else {
            return "Failed to create the zip file.";
        }
    }

    /**
     * Delete Database File From Local Folder.
     */
    private function __delete_db_file($file_name, $path)
    {
        $file_delete = File::delete(storage_path($path . $file_name));
        return $file_delete;
    }
}

<?php

namespace App\Http\Controllers;

use ArrayObject;
use Carbon\Carbon;
use DateTime;
use Exception;
use Hamcrest\Type\IsInteger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\Inline\Element\Newline;

use function PHPSTORM_META\map;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function saveBase64($data, $fileName, $overideExtension = null)
    {
        $image_64 = $data;
        $extension = explode('/', explode(':', substr($image_64, 0, strpos($image_64, ';')))[1])[1];   // .jpg .png .pdf
        $replace = substr($image_64, 0, strpos($image_64, ',')+1); 
      // find substring fro replace here eg: data:image/png;base64,
       $image = str_replace($replace, '', $image_64); 
       $image = str_replace(' ', '+', $image);
       $imageName = $fileName . '.' . ($overideExtension == null ? $extension : $overideExtension);
       Storage::disk('public')->put($imageName, base64_decode($image));
    }

    protected function csvToArray($str) {
        $arr = str_getcsv($str, "\n");
        $header = str_getcsv($arr[0], "\t");
        $data = [];
        $index1 = 0;
        foreach ($arr as $row) {
            if ($index1 == 0) {
                $index1++;
                continue;
            }
            $columns = str_getcsv($row, "\t");
            $index2 = 0;
            foreach($columns as $column) {
                $data[$index1][$header[$index2]] = $column;
                $index2++;
            }
            $index1++;
        }
        return $data;
    }

    protected function validateImport($table, $dataSource) {
        try {
            $data = collect($dataSource)->map(function($row){return collect($row);});
            $dataDB = DB::table($table)->select('*')->get();
            $columns = collect(DB::select(DB::raw('SHOW COLUMNS FROM ' . $table . '')))->map(function($row){return collect($row);});
            $foreignKeys = collect(DB::select(DB::raw('SELECT COLUMN_NAME AS Field, REFERENCED_TABLE_NAME as TableName, REFERENCED_COLUMN_NAME as TableField FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = "' . $table . '" AND REFERENCED_TABLE_NAME IS NOT NULL')))->map(function($row){return collect($row);});
            $foreignPrimaryKeys = [];
            foreach($foreignKeys as $foreignKey) {
                $field = $foreignKey->get('Field');
                $tableField = $foreignKey->get('TableField');
                $tableName = $foreignKey->get('TableName');
                $foreignData = collect(DB::select(DB::raw('select ' . $tableField . ' from ' . $tableName)));
                $foreignPrimaryKeys[$field] = $foreignData;
            }
            //Ki???m tra kh??ng c?? c???t m?? kh??ng cho ph??p null
            $missingColumns = $columns->where('Null', 'NO')->where('Key', '!=', 'PRI')->whereNotIn('Field', $data->first()->keys());
            if ($missingColumns->isNotEmpty())
                return 'Thi???u tr?????ng ' . $missingColumns->first()['Field'] . ' trong file import, l?? tr?????ng b???t bu???c ph???i c?? trong c?? s??? d??? li???u';
            
            $count = 1;
            foreach($data as $dataItem) {
                foreach($columns->whereIn('Field', $data->first()->keys()) as $column) {
                    $field = $column->get('Field');
                    $type = $column->get('Type');
                    $isNull = $column->get('Null') == 'YES' ? true : false;
                    $value = $dataItem->get($field);
                    $key = $column->get('Key');
                    $foreign = $foreignKeys->where('Field', $field)->first();
                    $error = 'L???i ??? d??ng s??? ' . $count . ': Sai gi?? tr??? "' . $value . '" trong c???t "' . $field . '" - ';
                    
                    //Ki???m tra xung ?????t unique value
                    foreach($dataDB->pluck($field) as $dataDBItem) {
                        if ($key == 'UNI' && $value == $dataDBItem)
                            return $error . 'Tr??ng l???p v???i c?? s??? d??? li???u, y??u c???u m???i gi?? tr??? l?? duy nh???t.';
                    }   
                    //Ki???m tra c?? gi?? tr??? null trong tr?????ng kh??ng cho ph??p null
                    if (!$isNull && $value == "")
                        return $error . 'L?? tr?????ng kh??ng ???????c ph??p null';
                    //Ki???m tra ki???u d??? li???u s???
                    if ((strpos($type, 'int') || strpos($type, 'year')) && !is_int($value+0))
                        return $error . 'y??u c???u ki???u d??? li???u l?? integer';
                        if (strpos($type, 'double') && !is_double($value+0))
                        return $error . 'Y??u c???u ki???u d??? li???u l?? double';
                    //Ki???m tra ki???u d??? li???u date
                    if ($type == 'date' && !DateTime::createFromFormat('Y-m-d', $value))
                        return $error . 'Y??u c???u ki???u d??? li???u l?? date: yyyy-mm-dd';
                    //Ki???m tra ki???u d??? li???u datetime
                    if ($type == 'datetime' && !DateTime::createFromFormat('Y-m-d H:i:s', $value))
                        return $error . 'Y??u c???u ki???u d??? li???u l?? date: yyyy-mm-dd hh:ii::ss';
                    //Ki???m tra kh??a ngo???i
                    if ($key == 'MUL' && $value != "" && $foreign != null) {
                            if ($foreignPrimaryKeys[$field]->where($foreign->get('TableField'), $value)->isEmpty())
                                return $error . 'Kh??a ngo???i kh??ng t???n t???i trong b???ng "' . $foreign->get('TableName') . '"';
                    }
                }
                $count++;
            }
        } catch(Exception $e) {
            return $e->getMessage();
        }
        return null;
    }

    //Ki???m tra x??a
    protected function validateDelete(string $table, Array $ids): string {
        try {
            if ($ids == [] || $ids == null)
                return 'Tr???ng, vui l??ng ch???n b???n ghi ????? x??a!';
            $foreign = collect(DB::select(DB::raw('SELECT TABLE_NAME AS TableName, COLUMN_NAME AS ColumnName, CONSTRAINT_NAME AS ConstraintName, REFERENCED_TABLE_NAME AS ReferencedTableName, REFERENCED_COLUMN_NAME AS ReferencedColumnName FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME = "' . $table . '"')))->map(function($row){return collect($row);});
            if ($foreign->count() == 0)
                return "";
            foreach($foreign as $value) {
                $tableName = $value->get('TableName');
                $columnName = $value->get('ColumnName');
                $id = DB::table($tableName)->whereIn($columnName, $ids)->pluck($columnName);
                if (!$id->isEmpty())
                    return "D??? li???u n??y ???????c ??ang ???????c s??? d???ng. Vi???c x??a s??? d???n ?????n m???t d??? li???u li??n quan.\n Nh???n m???t l???n n???a ????? x??c nh???n x??a.";
            }
        } catch(Exception $e) {
            return $e->getMessage();
        }
        return "";
    }

    //Index
    protected function indexDB($data, Array $selection = null, Array $searching = null, Array $filters = null) {
        //Searching
        if ($searching != null) {
            $keyword = $searching['keyword'];
            $columns = $searching['columns'];
            $data = $data->where(function($data) use ($keyword, $columns) {
                $first = true;
                foreach ($columns as $column) {
                    if ($first == true)
                        $data = $data->where($column, 'like', '%' . $keyword . '%');
                    else
                        $data->orWhere($column, 'like', '%' . $keyword . '%');
                    $first = false;
                }
            });
        };

        //Filter
        if ($filters != null) {
            foreach ($filters as $filter) {
                $filter_column = $filter['column'];
                $filter_values = $filter['values'];
                if ($filter_values != null)
                    $data = $data->whereIn($filter_column, $filter_values);
            }
        }

        //Selection
        $data = $data->select($selection);

        return $data;
    }

    //X??a b???n ghi li??n quan
    protected function deleteRelation(string $table, Array $ids) {
        try {
            if ($ids == [] || $ids == null)
                return;
            $foreign = collect(DB::select(DB::raw('SELECT TABLE_NAME AS TableName, COLUMN_NAME AS ColumnName, CONSTRAINT_NAME AS ConstraintName, REFERENCED_TABLE_NAME AS ReferencedTableName, REFERENCED_COLUMN_NAME AS ReferencedColumnName FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME = "' . $table . '"')))->map(function($row){return collect($row);});
            if ($foreign->count() == 0)
                return;
            foreach($foreign as $value) {
                $tableName = $value->get('TableName');
                $columnName = $value->get('ColumnName');
                $tableIds = DB::table($tableName)->whereIn($columnName, $ids)->pluck('id')->toArray();
                $this->deleteRelation($tableName, $tableIds);
                $this->deleteDB($tableName, $tableIds, false, true, true);
            }
        } catch(Exception $e) {
            return $e->getMessage();
        }
    }

    //Ch??n c?? s??? d??? li???u
    protected function insertDB($table, $data, $addLog = true, $continuePreviousStep = false) {
        $avatar = null;
        if (isset($data['avatar']))
            $avatar = $data['avatar'];
        if (!isset($data['created_at']))
            $data['created_at'] = Carbon::now();
        unset($data['avatar']);
        $id = DB::table($table)->insertGetId($data);
        if ($addLog) {
            $step = DB::table('logs')->orderByDesc('id')->first();
            $nextStep = $step ? $step->step + ($continuePreviousStep ? 0 : 1) : 1;
            DB::table('logs')->insertGetId([
                'step' => $nextStep,
                'type' => 0,
                'table_name' => $table,
                'record_id' => $id,
                'created_at' => Carbon::now(),
            ]);
        }
        if ($avatar)
            $this->saveBase64($avatar, 'images/' . $table . '/' . $id, 'png');
    }

    //Import
    protected function importDB($table, $data, $addLog = true) {
        $validate = $this->validateImport($table, $data);
        $next = false;
        if ($validate == null) {
            foreach ($data as $item) {
                $this->insertDB($table, $item, $addLog, $next);
                $next = true;
            }
        }
        return $validate;
    }

    //Ch???nh s???a c?? s??? d??? li???u
    protected function updateDB($table, $id, $data, $addLog = true, $continuePreviousStep = false) {
        $avatar = null;
        if (isset($data['avatar']))
            $avatar = $data['avatar'];
        if (!isset($data['updated_at']))
            $data['updated_at'] = Carbon::now();
        unset($data['avatar']);
        if ($addLog) {
            $step = DB::table('logs')->orderByDesc('id')->first();
            $nextStep = $step ? $step->step + ($continuePreviousStep ? 0 : 1) : 1;
            DB::table('logs')->insertGetId([
                'step' => $nextStep,
                'type' => 1,
                'table_name' => $table,
                'record_id' => $id,
                'data' => json_encode(DB::table($table)->where('id', $id)->select(array_keys($data))->first()),
                'created_at' => Carbon::now(),
            ]);
        }
        if ($data != null)
            DB::table($table)->where('id', $id)->update($data);
        if ($avatar)
            $this->saveBase64($avatar, 'images/' . $table . '/' . $id, 'png');
    }

    //X??a c?? s??? d??? li???u
    protected function deleteDB(string $table, Array $ids, bool $deleteRelation, $addLog = true, $continuePreviousStep = false) {
            $step = DB::table('logs')->orderByDesc('id')->first();
            $nextStep = $step ? $step->step + ($continuePreviousStep ? 0 : 1) : 1;
            if ($deleteRelation == true) {
                DB::table('logs')->insertGetId([
                    'step' => $nextStep,
                    'type' => -1,
                    'table_name' => '',
                    'record_id' => 0,
                    'created_at' => Carbon::now(),
                    'data' => ''
                ]);
                $this->deleteRelation($table, $ids);
            }
            $validate = $this->validateDelete($table, $ids);
            if ($validate == null) {
                foreach ($ids as $id) {
                    if ($addLog) {
                        DB::table('logs')->insertGetId([
                            'step' => $nextStep,
                            'type' => 2,
                            'table_name' => $table,
                            'record_id' => $id,
                            'data' => json_encode(DB::table($table)->where('id', $id)->first()),
                            'created_at' => Carbon::now(),
                        ]);
                    }
                    DB::table($table)->where('id', $id)->delete();
                    $imagePath = 'images/' . $table . '/' . $id . '.png';
                    if (Storage::disk('public')->exists($imagePath))
                        Storage::disk('public')->delete($imagePath);
                }
                return null;
            }
            else
                return $validate;
    }

    //Rollback l???i c?? s??? d??? li???u
    protected function rollbackDB() {
        $lastLog = DB::table('logs')->orderByDesc('id')->first();
        $log = null;
        if ($lastLog) {
            $logs = DB::table('logs')->where('step', $lastLog->step)->orderByDesc('id')->get();
            foreach ($logs as $log) {
                if ($log->type == 0)
                    $this->deleteDB($log->table_name, [$log->record_id], false, false);
                else if ($log->type == 1)
                    $this->updateDB($log->table_name, $log->record_id, json_decode($log->data, true), false);
                else if ($log->type == 2)
                    $this->insertDB($log->table_name, json_decode($log->data, true), false);
                DB::table('logs')->where('id', $log->id)->delete();
            }
            return response()->json(['type' => 'success', 'text' => '???? rollback l???i d??? li???u']);
        }
        else
            return response()->json(['type' => 'error', 'text' => 'Kh??ng c?? b???n log ????? rollback']);
    }
}

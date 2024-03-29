<?php
require './vendor/autoload.php';

$configDb = [
	'type'     => 'pgsql',
    'hostname' => '127.0.0.1',
    'port' => '5432',
    'database' => 'db',
    'username' => 'root',
    'password' => '',
];

try {
	$pgsql = new PDO("{$configDb['type']}:host={$configDb['hostname']};port={$configDb['port']};dbname={$configDb['database']}", $configDb['username'], $configDb['password']);
	$pgsql->query("SET NAMES utf8mb4");
} catch (PDOException $e) {
	exit('Failed to connect to database' . $e->getMessage());
}

$res = $pgsql->query("SELECT * FROM pg_catalog.pg_tables WHERE schemaname = 'public'");
$tables = [];
while ($row = $res->fetch()) {
	array_push($tables, [
		'name'      	=> $row['tablename'],
		'schemaname'	=> $row['schemaname'],
		'tableowner' 	=> $row['tableowner'],
	]);
}

foreach ($tables as $index => $val) {
	$res = $pgsql->query("
	WITH vars AS ( SELECT 'public' AS v_SchemaName -- Set to the schema whose tables you want in the Data Dictionary
		, 'NO' AS v_TablesOnly -- YES=Limit To Tables only; NO=Include views too
	),
	baseTbl AS (
		SELECT
			table_schema AS SchemaName,
			table_catalog,
			table_type,
			TABLE_NAME,
			table_schema 
		FROM
			INFORMATION_SCHEMA.TABLES 
		WHERE
			TABLE_SCHEMA = ( SELECT v_SchemaName FROM vars ) 
			AND (
				( TABLE_TYPE = 'BASE TABLE' ) 
				OR ( ( SELECT v_TablesOnly FROM vars ) = 'NO' ) 
			) 
		),
		metadata AS (
		SELECT
			bt.SchemaName AS schema_nm,
			bt.TABLE_NAME AS table_nm,
		CASE
				
				WHEN bt.TABLE_TYPE = 'BASE TABLE' THEN
				'TBL' 
				WHEN bt.TABLE_TYPE = 'VIEW' THEN
				'VW' ELSE'UK' 
			END AS obj_typ,
			tut.ordinal_position AS ord_pos,
			tut.COLUMN_NAME AS column_nm,
			CONCAT (
				COALESCE ( tut.data_type, 'unknown' ),
			CASE
					
					WHEN tut.data_type IN ( 'varchar', 'char' ) THEN
					CONCAT (
						'(',
						CAST ( tut.CHARACTER_MAXIMUM_LENGTH AS VARCHAR ( 10 ) ),
						')' 
					) 
					WHEN tut.data_type IN ( 'date', 'time' ) THEN
					CONCAT ( '(3)' ) 
					WHEN tut.data_type = 'datetime' THEN
					CONCAT ( '(8)' ) 
					WHEN tut.data_type = 'timestamp' THEN
					CONCAT ( '(4)' ) 
					WHEN tut.data_type IN ( 'bigint', 'integer', 'smallint' ) THEN
					CONCAT (
						'(',
						CAST ( tut.NUMERIC_PRECISION AS VARCHAR ( 10 ) ),
						')' 
					) 
					WHEN tut.data_type = 'decimal' THEN
					CONCAT (
						'(',
						CAST ( tut.NUMERIC_PRECISION AS VARCHAR ( 10 ) ),
						',',
						CAST ( tut.NUMERIC_SCALE AS VARCHAR ( 10 ) ),
						')' 
					) 
					WHEN tut.CHARACTER_MAXIMUM_LENGTH IS NOT NULL THEN
					CONCAT (
						'(',
						CAST ( tut.CHARACTER_MAXIMUM_LENGTH AS VARCHAR ( 10 ) ),
						')' 
					) 
					WHEN tut.DATETIME_PRECISION IS NOT NULL THEN
					CONCAT (
						'(',
						CAST ( tut.DATETIME_PRECISION AS VARCHAR ( 10 ) ),
						')' 
					) 
					WHEN tut.NUMERIC_PRECISION IS NOT NULL 
					AND tut.NUMERIC_SCALE IS NULL THEN
						CONCAT (
							'(',
							CAST ( tut.NUMERIC_PRECISION AS VARCHAR ( 10 ) ),
							')' 
						) 
						WHEN tut.NUMERIC_PRECISION IS NOT NULL 
						AND tut.NUMERIC_SCALE IS NOT NULL THEN
							CONCAT (
								'(',
								CAST ( tut.NUMERIC_PRECISION AS VARCHAR ( 10 ) ),
								',',
								CAST ( tut.NUMERIC_SCALE AS VARCHAR ( 10 ) ),
								')' 
							) ELSE'' 
						END 
						) AS data_typ,
					CASE
							
							WHEN tut.IS_NULLABLE = 'YES' THEN
							'NULL' ELSE'NOT NULL' 
					END AS NULLABLE 
					FROM
						INFORMATION_SCHEMA.COLUMNS tut
						INNER JOIN baseTbl bt ON bt.table_catalog = tut.TABLE_CATALOG 
						AND bt.TABLE_NAME = tut.TABLE_NAME 
					),
					meta_for_keys AS (
					SELECT
						schema_nm,
						table_nm,
						column_nm,
						STRING_AGG ( is_key, ',' ORDER BY is_key ) AS is_key 
					FROM
						(
						SELECT
							cons.TABLE_SCHEMA AS schema_nm,
							cons.TABLE_NAME AS table_nm,
							kcu.COLUMN_NAME AS column_nm,
						CASE
								
								WHEN cons.constraint_type = 'PRIMARY KEY' THEN
								'PK' 
								WHEN cons.constraint_type = 'UNIQUE' THEN
								'UK' 
								WHEN cons.constraint_type = 'FOREIGN KEY' THEN
								'FK' ELSE'X' 
							END AS is_key 
						FROM
							INFORMATION_SCHEMA.TABLE_CONSTRAINTS cons
							INNER JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON cons.TABLE_SCHEMA = kcu.TABLE_SCHEMA 
							AND cons.TABLE_NAME = kcu.TABLE_NAME 
							AND cons.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME 
						WHERE
							cons.table_schema = ( SELECT v_SchemaName FROM vars ) 
							AND cons.TABLE_NAME IN ( SELECT DISTINCT TABLE_NAME FROM baseTbl ) 
							AND cons.constraint_type IN ( 'PRIMARY KEY', 'FOREIGN KEY', 'UNIQUE' ) 
						GROUP BY
							cons.TABLE_SCHEMA,
							cons.TABLE_NAME,
							kcu.COLUMN_NAME,
							cons.constraint_type 
						) T 
					GROUP BY
						schema_nm,
						table_nm,
						column_nm 
					),
					col_comm AS (
					SELECT C
						.TABLE_SCHEMA AS schema_nm,
						C.TABLE_NAME AS table_nm,
						C.COLUMN_NAME AS column_nm,
						pgd.DESCRIPTION AS column_descr 
					FROM
						pg_catalog.pg_statio_all_tables AS st
						INNER JOIN pg_catalog.PG_DESCRIPTION AS pgd ON pgd.objoid = st.relid
						INNER JOIN INFORMATION_SCHEMA.COLUMNS AS C ON pgd.objsubid = C.ordinal_position 
						AND C.table_schema = st.schemaname 
						AND C.TABLE_NAME = st.relname 
					WHERE
						C.table_schema = ( SELECT v_SchemaName FROM vars ) 
						AND C.TABLE_NAME IN ( SELECT DISTINCT TABLE_NAME FROM baseTbl ) 
					) SELECT
					md.SCHEMA_NM,
					md.TABLE_NM,
					md.OBJ_TYP,
					md.ORD_POS AS ord,
					COALESCE ( pk.is_key, ' ' ) AS is_key,
					md.COLUMN_NM,
					md.DATA_TYP,
					md.NULLABLE,
					C.column_descr 
				FROM
					metadata md
					LEFT JOIN meta_for_keys pk ON pk.SCHEMA_NM = md.SCHEMA_NM 
					AND pk.TABLE_NM = md.TABLE_NM 
					AND pk.COLUMN_NM = md.COLUMN_NM
					LEFT JOIN col_comm C ON C.SCHEMA_NM = md.SCHEMA_NM 
					AND C.TABLE_NM = md.TABLE_NM 
					AND C.COLUMN_NM = md.COLUMN_NM 
					WHERE md.TABLE_NM = '" . $val['name'] . "'
				ORDER BY
					md.SCHEMA_NM,
				md.TABLE_NM,
		md.ORD_POS");

	$fields = [];
	while ($row = $res->fetch()) {
		array_push($fields, [
			'field'     => $row['column_nm'],
			'type'      => $row['data_typ'],
			'collation' => '',
			'null'      => $row['nullable'],
			'key'       => $row['is_key'],
			'default'   => '',
			'extra'     => '',
			'comment'   => $row['column_descr'],
		]);
	}
	$tables[$index]['field'] = $fields;
}

$excel = new PHPExcel();
$excel->getProperties()->setCreator('phanuwit.h@gmail.com');
$excel->getProperties()->setTitle($configDb['database']);

$excel->getDefaultStyle()->getFont()->setName('TH SarabunPSK')->setSize(16);

$excel->setActiveSheetIndex(0);
$excel->getActiveSheet()->setTitle('Data Dictionary');
$activeSheet = $excel->getActiveSheet();

$activeSheet->getColumnDimension('B')->setWidth(10);
$activeSheet->getColumnDimension('C')->setWidth(20);
$activeSheet->getColumnDimension('D')->setWidth(24);
$activeSheet->getColumnDimension('E')->setWidth(20);
$activeSheet->getColumnDimension('F')->setWidth(12);
$activeSheet->getColumnDimension('G')->setWidth(30);

$activeSheet->getDefaultStyle()->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
$activeSheet->getDefaultStyle()->getAlignment()->setVertical(PHPExcel_Style_Alignment::VERTICAL_CENTER);
$activeSheet->getDefaultRowDimension()->setRowHeight(20);
$styleArray = [
	'borders' => [
		'allborders' => [
			//'style' => PHPExcel_Style_Border::BORDER_THICK,
			'style' => PHPExcel_Style_Border::BORDER_THIN,
			//'color' => ['argb' => 'FFFF0000'],
		],
	],
];
$styleTitleArray = [
	'font'  => [
		'bold'  => true,
		'size'  => 18,
		'name'  => 'TH SarabunPSK'
	],
	'alignment' => array(
		'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_LEFT,
	)
];

$num = 1;
foreach ($tables as $key => $val) {
	$activeSheet->setCellValue('B' . $num, 'Table ' . ($key + 1) . ' : ' . $val['name']);
	$activeSheet->mergeCells('B' . $num . ':G' . $num);
	$activeSheet->getStyle('B' . $num)->applyFromArray($styleTitleArray);
	$num++;

	$start = $num;
	$activeSheet->setCellValue('B' . $num, 'No');
	$activeSheet->setCellValue('C' . $num, 'Column');
	$activeSheet->setCellValue('D' . $num, 'Data Type');
	$activeSheet->setCellValue('E' . $num, 'Nullable');
	$activeSheet->setCellValue('F' . $num, 'Key');
	$activeSheet->setCellValue('G' . $num, 'Description');
	$activeSheet->getStyle('B' . $num . ':G' . $num)->applyFromArray($styleTitleArray);
	$activeSheet->getStyle('B' . $num . ':G' . $num)->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
	$activeSheet->getStyle('B' . $num . ':G' . $num)->getFont()->setName('TH SarabunPSK')->setSize(16);
	$num++;
	foreach ($val['field'] as $k => $v) {
		$activeSheet->setCellValue('B' . $num, $k + 1);
		$activeSheet->setCellValue('C' . $num, $v['field']);
		$activeSheet->setCellValue('D' . $num, $v['type']);
		$activeSheet->setCellValue('E' . $num, $v['null']);
		$activeSheet->setCellValue('F' . $num, $v['key']);
		$activeSheet->setCellValue('G' . $num, $v['comment']);
		$num++;
	}
	$activeSheet->getStyle('B' . $start . ':G' . ($num - 1))->applyFromArray($styleArray);
	$num++;
}
$write = new PHPExcel_Writer_Excel2007($excel);
$write->save("data_dictionary_" . date('YmdHis') . ".xlsx");

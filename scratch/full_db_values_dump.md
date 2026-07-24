# Complete Database Value Dump: Planning, Jobs, Packing, Inventory & Dispatch
**Dump Time**: 2026-07-24 20:18:19

## 1. Full Planning Table Records
### Planning ID #1 (PLN/2026/0001)
```json
{
    "id": "1",
    "job_no": "PLN/2026/0001",
    "sales_order_id": null,
    "job_name": "Mewa laya",
    "machine": "",
    "operator_name": "",
    "scheduled_date": "2026-08-04",
    "status": "",
    "priority": "Normal",
    "department": "label-printing",
    "extra_data": "{\"printing_planning\":\"Label Slitted\",\"name\":\"Mewa laya\",\"priority\":\"Normal\",\"order_date\":\"2026-07-23\",\"dispatch_date\":\"2026-08-04\",\"plate_no\":\"1058\",\"paper_size\":\"195 mm\",\"size\":\"90mm X 149mm\",\"repeat\":\"152.4\",\"material\":\"CHROMO\",\"die\":\"FlatBed\",\"allocate_mtrs\":\"2000\",\"qty_pcs\":\"26247\",\"core_size\":\"1 inc\",\"qty_per_roll\":\"500 / OD 4.5 inc\",\"roll_direction\":\"Left First\",\"remarks\":\"\",\"image_path\":\"uploads/library/plate-data/plate_20260405_183215_1e3b626b.jpg\",\"image_name\":\"plate_20260405_183215_1e3b626b.jpg\",\"image_uploaded_at\":\"\",\"image_source\":\"plate\",\"ups\":\"2\",\"department_route\":\"Jumbo Slitting, Printing, Die-Cutting, Label Slitting, Packaging, Dispatch\"}",
    "sequence_order": "0",
    "notes": "",
    "created_by": "1",
    "created_at": "2026-07-24 22:21:34",
    "updated_at": "2026-07-24 23:38:27",
    "deleted_at": null,
    "extra_data_parsed": {
        "printing_planning": "Label Slitted",
        "name": "Mewa laya",
        "priority": "Normal",
        "order_date": "2026-07-23",
        "dispatch_date": "2026-08-04",
        "plate_no": "1058",
        "paper_size": "195 mm",
        "size": "90mm X 149mm",
        "repeat": "152.4",
        "material": "CHROMO",
        "die": "FlatBed",
        "allocate_mtrs": "2000",
        "qty_pcs": "26247",
        "core_size": "1 inc",
        "qty_per_roll": "500 / OD 4.5 inc",
        "roll_direction": "Left First",
        "remarks": "",
        "image_path": "uploads/library/plate-data/plate_20260405_183215_1e3b626b.jpg",
        "image_name": "plate_20260405_183215_1e3b626b.jpg",
        "image_uploaded_at": "",
        "image_source": "plate",
        "ups": "2",
        "department_route": "Jumbo Slitting, Printing, Die-Cutting, Label Slitting, Packaging, Dispatch"
    }
}
```

### Planning ID #2 (PLN-BAR/2026/0001)
```json
{
    "id": "2",
    "job_no": "PLN-BAR/2026/0001",
    "sales_order_id": null,
    "job_name": "Barcode | 100mm X 150mm | 2 Ups",
    "machine": null,
    "operator_name": null,
    "scheduled_date": "2026-08-05",
    "status": "",
    "priority": "Normal",
    "department": "barcode",
    "extra_data": "{\"planning_date\":\"2026-07-24\",\"dispatch_date\":\"2026-08-05\",\"priority\":\"Normal\",\"planning_type\":\"Regular\",\"client_name\":\"trdt\",\"material_type\":\"CHROMO\",\"order_quantity\":\"15\",\"order_meter\":\"1147.5\",\"order_quantity_user\":\"15000\",\"order_meter_user\":\"1147.5\",\"pcs_per_roll\":\"250\",\"barcode_size\":\"100mm X 150mm\",\"up_in_roll\":\"1\",\"up_in_production\":\"2\",\"label_gap\":\"3mm\",\"both_side_gap\":\"20\",\"paper_size\":\"220\",\"job_label\":\"Barcode | 100mm X 150mm | 2 Ups | 100mm X 150mm | 2 Ups\",\"cylinder\":\"NA\",\"repeat\":\"153\",\"use\":\"Barcode\",\"die_type\":\"Flatbed\",\"core\":\"1 INC\",\"printing_planning\":\"Label Slitted\"}",
    "sequence_order": "1",
    "notes": "Barcode planning entry",
    "created_by": "1",
    "created_at": "2026-07-24 22:22:00",
    "updated_at": "2026-07-24 23:39:01",
    "deleted_at": null,
    "extra_data_parsed": {
        "planning_date": "2026-07-24",
        "dispatch_date": "2026-08-05",
        "priority": "Normal",
        "planning_type": "Regular",
        "client_name": "trdt",
        "material_type": "CHROMO",
        "order_quantity": "15",
        "order_meter": "1147.5",
        "order_quantity_user": "15000",
        "order_meter_user": "1147.5",
        "pcs_per_roll": "250",
        "barcode_size": "100mm X 150mm",
        "up_in_roll": "1",
        "up_in_production": "2",
        "label_gap": "3mm",
        "both_side_gap": "20",
        "paper_size": "220",
        "job_label": "Barcode | 100mm X 150mm | 2 Ups | 100mm X 150mm | 2 Ups",
        "cylinder": "NA",
        "repeat": "153",
        "use": "Barcode",
        "die_type": "Flatbed",
        "core": "1 INC",
        "printing_planning": "Label Slitted"
    }
}
```

### Planning ID #3 (PLN-POS/2026/0001)
```json
{
    "id": "3",
    "job_no": "PLN-POS/2026/0001",
    "sales_order_id": null,
    "job_name": "POS-7950",
    "machine": null,
    "operator_name": null,
    "scheduled_date": "2026-08-05",
    "status": "",
    "priority": "Normal",
    "department": "paperroll",
    "extra_data": "{\"planning_date\":\"2026-07-24\",\"dispatch_date\":\"2026-08-05\",\"planning_type\":\"pos_roll\",\"priority\":\"Normal\",\"client_name\":\"test1\",\"material_type\":\"Thermal Paper\",\"order_quantity\":\"18\",\"order_meter\":\"63000\",\"order_quantity_user\":\"1800\",\"order_meter_user\":\"63000\",\"pcs_per_roll\":\"\",\"barcode_size\":\"\",\"up_in_roll\":\"\",\"up_in_production\":\"\",\"label_gap\":\"\",\"both_side_gap\":\"\",\"paper_size\":\"78mm x 35mtr\",\"job_label\":\"POS-7950\",\"cylinder\":\"\",\"repeat\":\"\",\"use\":\"\",\"die_type\":\"\",\"core\":\"\",\"item_width\":\"78\",\"item_length\":\"35\",\"gsm\":\"72\",\"roll_dia\":\"60\",\"core_size\":\"13 x 17\",\"core_type\":\"Plastic Core\",\"printing_planning\":\"Packing\"}",
    "sequence_order": "1",
    "notes": "PaperRoll planning entry",
    "created_by": "1",
    "created_at": "2026-07-24 22:22:38",
    "updated_at": "2026-07-24 23:35:18",
    "deleted_at": null,
    "extra_data_parsed": {
        "planning_date": "2026-07-24",
        "dispatch_date": "2026-08-05",
        "planning_type": "pos_roll",
        "priority": "Normal",
        "client_name": "test1",
        "material_type": "Thermal Paper",
        "order_quantity": "18",
        "order_meter": "63000",
        "order_quantity_user": "1800",
        "order_meter_user": "63000",
        "pcs_per_roll": "",
        "barcode_size": "",
        "up_in_roll": "",
        "up_in_production": "",
        "label_gap": "",
        "both_side_gap": "",
        "paper_size": "78mm x 35mtr",
        "job_label": "POS-7950",
        "cylinder": "",
        "repeat": "",
        "use": "",
        "die_type": "",
        "core": "",
        "item_width": "78",
        "item_length": "35",
        "gsm": "72",
        "roll_dia": "60",
        "core_size": "13 x 17",
        "core_type": "Plastic Core",
        "printing_planning": "Packing"
    }
}
```

### Planning ID #4 (PLN-1PL/2026/0001)
```json
{
    "id": "4",
    "job_no": "PLN-1PL/2026/0001",
    "sales_order_id": null,
    "job_name": "75mm - 1Ply",
    "machine": null,
    "operator_name": null,
    "scheduled_date": "2026-08-05",
    "status": "",
    "priority": "Normal",
    "department": "paperroll",
    "extra_data": "{\"planning_date\":\"2026-07-24\",\"dispatch_date\":\"2026-08-05\",\"planning_type\":\"one_ply\",\"priority\":\"Normal\",\"client_name\":\"test100\",\"material_type\":\"Maplitho Without Gum\",\"order_quantity\":\"25\",\"order_meter\":\"87500\",\"order_quantity_user\":\"2500\",\"order_meter_user\":\"87500\",\"pcs_per_roll\":\"\",\"barcode_size\":\"\",\"up_in_roll\":\"\",\"up_in_production\":\"\",\"label_gap\":\"\",\"both_side_gap\":\"\",\"paper_size\":\"75mm x 35mtr\",\"job_label\":\"75mm - 1Ply\",\"cylinder\":\"\",\"repeat\":\"\",\"use\":\"\",\"die_type\":\"\",\"core\":\"\",\"item_width\":\"75\",\"item_length\":\"35\",\"gsm\":\"60\",\"roll_dia\":\"60\",\"core_size\":\"13 x 17\",\"core_type\":\"Paper Core\",\"printing_planning\":\"Packing\"}",
    "sequence_order": "2",
    "notes": "PaperRoll planning entry",
    "created_by": "1",
    "created_at": "2026-07-24 22:23:04",
    "updated_at": "2026-07-24 23:36:03",
    "deleted_at": null,
    "extra_data_parsed": {
        "planning_date": "2026-07-24",
        "dispatch_date": "2026-08-05",
        "planning_type": "one_ply",
        "priority": "Normal",
        "client_name": "test100",
        "material_type": "Maplitho Without Gum",
        "order_quantity": "25",
        "order_meter": "87500",
        "order_quantity_user": "2500",
        "order_meter_user": "87500",
        "pcs_per_roll": "",
        "barcode_size": "",
        "up_in_roll": "",
        "up_in_production": "",
        "label_gap": "",
        "both_side_gap": "",
        "paper_size": "75mm x 35mtr",
        "job_label": "75mm - 1Ply",
        "cylinder": "",
        "repeat": "",
        "use": "",
        "die_type": "",
        "core": "",
        "item_width": "75",
        "item_length": "35",
        "gsm": "60",
        "roll_dia": "60",
        "core_size": "13 x 17",
        "core_type": "Paper Core",
        "printing_planning": "Packing"
    }
}
```

### Planning ID #5 (PLN-2PL/2026/0001)
```json
{
    "id": "5",
    "job_no": "PLN-2PL/2026/0001",
    "sales_order_id": null,
    "job_name": "210mm-2Ply",
    "machine": null,
    "operator_name": null,
    "scheduled_date": "2026-08-05",
    "status": "",
    "priority": "Normal",
    "department": "paperroll",
    "extra_data": "{\"planning_date\":\"2026-07-24\",\"dispatch_date\":\"2026-08-05\",\"planning_type\":\"two_ply\",\"priority\":\"Normal\",\"client_name\":\"testy6\",\"material_type\":\"Carbonless\",\"order_quantity\":\"35\",\"order_meter\":\"126000\",\"order_quantity_user\":\"3500\",\"order_meter_user\":\"126000\",\"pcs_per_roll\":\"\",\"barcode_size\":\"\",\"up_in_roll\":\"\",\"up_in_production\":\"\",\"label_gap\":\"\",\"both_side_gap\":\"\",\"paper_size\":\"210mm x 36mtr\",\"job_label\":\"210mm-2Ply\",\"cylinder\":\"\",\"repeat\":\"\",\"use\":\"\",\"die_type\":\"\",\"core\":\"\",\"item_width\":\"210\",\"item_length\":\"36\",\"gsm\":\"60\",\"roll_dia\":\"87.5\",\"core_size\":\"1 Inch\",\"core_type\":\"Paper Core\",\"printing_planning\":\"Packing\"}",
    "sequence_order": "3",
    "notes": "PaperRoll planning entry",
    "created_by": "1",
    "created_at": "2026-07-24 22:23:30",
    "updated_at": "2026-07-24 23:36:44",
    "deleted_at": null,
    "extra_data_parsed": {
        "planning_date": "2026-07-24",
        "dispatch_date": "2026-08-05",
        "planning_type": "two_ply",
        "priority": "Normal",
        "client_name": "testy6",
        "material_type": "Carbonless",
        "order_quantity": "35",
        "order_meter": "126000",
        "order_quantity_user": "3500",
        "order_meter_user": "126000",
        "pcs_per_roll": "",
        "barcode_size": "",
        "up_in_roll": "",
        "up_in_production": "",
        "label_gap": "",
        "both_side_gap": "",
        "paper_size": "210mm x 36mtr",
        "job_label": "210mm-2Ply",
        "cylinder": "",
        "repeat": "",
        "use": "",
        "die_type": "",
        "core": "",
        "item_width": "210",
        "item_length": "36",
        "gsm": "60",
        "roll_dia": "87.5",
        "core_size": "1 Inch",
        "core_type": "Paper Core",
        "printing_planning": "Packing"
    }
}
```

## 2. Full Jobs Table Records
### Job ID #1 (JMB/2026/0001 - jumbo_slitting)
```json
{
    "id": "1",
    "job_no": "JMB/2026/0001",
    "planning_id": "1",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0232",
    "job_type": "Slitting",
    "status": "Closed",
    "started_at": "2026-07-24 23:17:57",
    "completed_at": "2026-07-24 23:18:06",
    "operator_id": null,
    "notes": "Jumbo grouped slitting job | Plan: PLN/2026/0001 | JMB: JMB/2026/0001 | Job Name : Mewa laya",
    "created_at": "2026-07-24 22:33:41",
    "updated_at": "2026-07-24 23:18:06",
    "extra_data": "{\"plan_no\":\"PLN\\/2026\\/0001\",\"parent_roll\":\"SLC\\/2026\\/0232\",\"parent_details\":{\"roll_no\":\"SLC\\/2026\\/0232\",\"company\":\"RAJ PAPER\",\"paper_type\":\"CHROMO\",\"width_mm\":1000,\"length_mtr\":3000,\"gsm\":184,\"weight_kg\":0,\"sqm\":3000,\"original_status\":\"Main\",\"remarks\":\"\"},\"child_rolls\":[{\"roll_no\":\"SLC\\/2026\\/0232-A\",\"parent_roll_no\":\"SLC\\/2026\\/0232\",\"width\":500,\"length\":3000,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":1,\"plan_no\":\"PLN\\/2026\\/0001\",\"allocation_id\":1,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, Printing, Die-Cutting, Label Slitting, Packaging, Dispatch\",\"job_no\":\"PLN\\/2026\\/0001\",\"job_name\":\"Mewa laya\",\"company\":\"RAJ PAPER\",\"paper_type\":\"CHROMO\",\"gsm\":184,\"weight_kg\":0,\"sqm\":1500,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"},{\"roll_no\":\"SLC\\/2026\\/0232-B\",\"parent_roll_no\":\"SLC\\/2026\\/0232\",\"width\":500,\"length\":3000,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":1,\"plan_no\":\"PLN\\/2026\\/0001\",\"allocation_id\":1,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, Printing, Die-Cutting, Label Slitting, Packaging, Dispatch\",\"job_no\":\"PLN\\/2026\\/0001\",\"job_name\":\"Mewa laya\",\"company\":\"RAJ PAPER\",\"paper_type\":\"CHROMO\",\"gsm\":184,\"weight_kg\":0,\"sqm\":1500,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"}],\"stock_rolls\":[],\"total_roll_count\":3,\"total_qty_mtr\":6000,\"material\":\"CHROMO\",\"paper_combined\":false,\"batch_no\":\"BAT\\/2026\\/0001\",\"machine\":\"Jumbo Sliting\",\"operator_name\":\"System Admin\",\"flexo_request_accept_flow\":false,\"flexo_target_department\":\"jumbo slitting\",\"flexo_request_id\":0,\"flexo_job_id\":0,\"flexo_task_index\":-1,\"plan_allocations\":[{\"allocation_id\":1,\"planning_id\":1,\"plan_no\":\"PLN\\/2026\\/0001\",\"job_name\":\"Mewa laya\",\"job_size\":\"90mm X 149mm\",\"department_route\":\"Jumbo Slitting, Printing, Die-Cutting, Label Slitting, Packaging, Dispatch\",\"allocated_width_mm\":1000,\"allocated_length_mtr\":6000,\"allocation_sequence\":1}],\"timer_accumulated_seconds\":3,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24T17:47:57.462Z\",\"timer_events\":[{\"type\":\"end\",\"at\":\"2026-07-24 19:48:00\"},{\"type\":\"end\",\"at\":\"2026-07-24 19:48:00\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 19:48:06\",\"timer_work_segments\":[{\"from\":\"2026-07-24 19:47:57\",\"to\":\"2026-07-24 19:48:00\",\"seconds\":3}],\"wastage_kg\":\"1\",\"operator_notes\":\"\"}",
    "duration_minutes": "0",
    "sequence_order": "1",
    "department": "jumbo_slitting",
    "previous_job_id": null,
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "plan_no": "PLN/2026/0001",
        "parent_roll": "SLC/2026/0232",
        "parent_details": {
            "roll_no": "SLC/2026/0232",
            "company": "RAJ PAPER",
            "paper_type": "CHROMO",
            "width_mm": 1000,
            "length_mtr": 3000,
            "gsm": 184,
            "weight_kg": 0,
            "sqm": 3000,
            "original_status": "Main",
            "remarks": ""
        },
        "child_rolls": [
            {
                "roll_no": "SLC/2026/0232-A",
                "parent_roll_no": "SLC/2026/0232",
                "width": 500,
                "length": 3000,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 1,
                "plan_no": "PLN/2026/0001",
                "allocation_id": 1,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, Printing, Die-Cutting, Label Slitting, Packaging, Dispatch",
                "job_no": "PLN/2026/0001",
                "job_name": "Mewa laya",
                "company": "RAJ PAPER",
                "paper_type": "CHROMO",
                "gsm": 184,
                "weight_kg": 0,
                "sqm": 1500,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            },
            {
                "roll_no": "SLC/2026/0232-B",
                "parent_roll_no": "SLC/2026/0232",
                "width": 500,
                "length": 3000,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 1,
                "plan_no": "PLN/2026/0001",
                "allocation_id": 1,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, Printing, Die-Cutting, Label Slitting, Packaging, Dispatch",
                "job_no": "PLN/2026/0001",
                "job_name": "Mewa laya",
                "company": "RAJ PAPER",
                "paper_type": "CHROMO",
                "gsm": 184,
                "weight_kg": 0,
                "sqm": 1500,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            }
        ],
        "stock_rolls": [],
        "total_roll_count": 3,
        "total_qty_mtr": 6000,
        "material": "CHROMO",
        "paper_combined": false,
        "batch_no": "BAT/2026/0001",
        "machine": "Jumbo Sliting",
        "operator_name": "System Admin",
        "flexo_request_accept_flow": false,
        "flexo_target_department": "jumbo slitting",
        "flexo_request_id": 0,
        "flexo_job_id": 0,
        "flexo_task_index": -1,
        "plan_allocations": [
            {
                "allocation_id": 1,
                "planning_id": 1,
                "plan_no": "PLN/2026/0001",
                "job_name": "Mewa laya",
                "job_size": "90mm X 149mm",
                "department_route": "Jumbo Slitting, Printing, Die-Cutting, Label Slitting, Packaging, Dispatch",
                "allocated_width_mm": 1000,
                "allocated_length_mtr": 6000,
                "allocation_sequence": 1
            }
        ],
        "timer_accumulated_seconds": 3,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24T17:47:57.462Z",
        "timer_events": [
            {
                "type": "end",
                "at": "2026-07-24 19:48:00"
            },
            {
                "type": "end",
                "at": "2026-07-24 19:48:00"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 19:48:06",
        "timer_work_segments": [
            {
                "from": "2026-07-24 19:47:57",
                "to": "2026-07-24 19:48:00",
                "seconds": 3
            }
        ],
        "wastage_kg": "1",
        "operator_notes": ""
    }
}
```

### Job ID #2 (FLX/2026/0001 - flexo_printing)
```json
{
    "id": "2",
    "job_no": "FLX/2026/0001",
    "planning_id": "1",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0232",
    "job_type": "Printing",
    "status": "Completed",
    "started_at": "2026-07-24 23:33:25",
    "completed_at": "2026-07-24 23:33:58",
    "operator_id": null,
    "notes": "Flexo printing queued from Jumbo | Plan: PLN/2026/0001 | Jumbo: JMB/2026/0001 I Flexo: FLX/2026/0001 | Job name : Mewa laya",
    "created_at": "2026-07-24 22:33:41",
    "updated_at": "2026-07-24 23:33:58",
    "extra_data": "{\"timer_accumulated_seconds\":3,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24T18:03:25.635Z\",\"timer_events\":[{\"type\":\"start\",\"at\":\"2026-07-24T18:03:25.635Z\"},{\"type\":\"end\",\"at\":\"2026-07-24 20:03:28\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 20:03:58\",\"timer_work_segments\":[{\"from\":\"2026-07-24 20:03:25\",\"to\":\"2026-07-24 20:03:28\",\"seconds\":3}],\"mkd_job_sl_no\":\"\",\"job_date\":\"2026-07-23\",\"job_name\":\"Mewa laya\",\"die\":\"FlatBed\",\"plate_no\":\"1058\",\"material_company\":\"RAJ PAPER\",\"material_name\":\"CHROMO\",\"order_mtr\":2000,\"order_qty\":26247,\"reel_no_c1\":\"\",\"reel_no_c2\":\"\",\"width_c1\":1000,\"width_c2\":1000,\"length_c1\":3000,\"length_c2\":3000,\"label_size\":\"90mm X 149mm\",\"repeat_mm\":\"152.4\",\"direction\":\"Left First\",\"actual_qty\":26000,\"electricity\":\"10\",\"time_spent\":\"00:00:03\",\"prepared_by\":\"System Admin\",\"filled_by\":\"System Admin\",\"defects_text\":\"\",\"physical_print_photo_url\":\"\",\"physical_print_photo_path\":\"\",\"total_wastage_meters\":\"\",\"colour_lanes\":[\"Cyan\",\"Magenta\",\"Yellow\",\"Black\",\"None\",\"None\",\"None\",\"None\"],\"anilox_lanes\":[\"None\",\"None\",\"None\",\"None\",\"None\",\"None\",\"None\",\"None\"],\"material_rows\":[{\"idx\":1,\"roll_no\":\"SLC\\/2026\\/0232-A\",\"parent_roll_no\":\"SLC\\/2026\\/0232\",\"slitted_width\":\"500\",\"slitted_length\":\"3000\",\"material_company\":\"RAJ PAPER\",\"material_name\":\"CHROMO\",\"order_mtr\":\"2000\",\"order_qty\":\"26247\",\"color_match_status\":\"Matched\",\"wastage_meters\":\"\"},{\"idx\":2,\"roll_no\":\"SLC\\/2026\\/0232-B\",\"parent_roll_no\":\"SLC\\/2026\\/0232\",\"slitted_width\":\"500\",\"slitted_length\":\"3000\",\"material_company\":\"RAJ PAPER\",\"material_name\":\"CHROMO\",\"order_mtr\":\"2000\",\"order_qty\":\"26247\",\"color_match_status\":\"Matched\",\"wastage_meters\":\"\"}],\"roll_wastage_rows\":[{\"idx\":1,\"roll_no\":\"SLC\\/2026\\/0232-A\",\"parent_roll_no\":\"SLC\\/2026\\/0232\",\"slitted_width\":\"500\",\"slitted_length\":\"3000\",\"material_company\":\"RAJ PAPER\",\"material_name\":\"CHROMO\",\"order_mtr\":\"2000\",\"order_qty\":\"26247\",\"color_match_status\":\"Matched\",\"wastage_meters\":\"\"},{\"idx\":2,\"roll_no\":\"SLC\\/2026\\/0232-B\",\"parent_roll_no\":\"SLC\\/2026\\/0232\",\"slitted_width\":\"500\",\"slitted_length\":\"3000\",\"material_company\":\"RAJ PAPER\",\"material_name\":\"CHROMO\",\"order_mtr\":\"2000\",\"order_qty\":\"26247\",\"color_match_status\":\"Matched\",\"wastage_meters\":\"\"}],\"color_anilox_rows\":[{\"lane\":1,\"color_code\":\"Cyan\",\"color_name\":\"Cyan\",\"anilox_value\":\"None\",\"anilox_custom\":\"\"},{\"lane\":2,\"color_code\":\"Magenta\",\"color_name\":\"Magenta\",\"anilox_value\":\"None\",\"anilox_custom\":\"\"},{\"lane\":3,\"color_code\":\"Yellow\",\"color_name\":\"Yellow\",\"anilox_value\":\"None\",\"anilox_custom\":\"\"},{\"lane\":4,\"color_code\":\"Black\",\"color_name\":\"Black\",\"anilox_value\":\"None\",\"anilox_custom\":\"\"},{\"lane\":5,\"color_code\":\"None\",\"color_name\":\"None\",\"anilox_value\":\"None\",\"anilox_custom\":\"\"},{\"lane\":6,\"color_code\":\"None\",\"color_name\":\"None\",\"anilox_value\":\"None\",\"anilox_custom\":\"\"},{\"lane\":7,\"color_code\":\"None\",\"color_name\":\"None\",\"anilox_value\":\"None\",\"anilox_custom\":\"\"},{\"lane\":8,\"color_code\":\"None\",\"color_name\":\"None\",\"anilox_value\":\"None\",\"anilox_custom\":\"\"}],\"ink_colors\":\"\",\"cylinder_ref\":\"\",\"impression_count\":\"\",\"print_speed\":\"\",\"color_match_status\":\"Matched\",\"wastage_meters\":\"\",\"operator_notes\":\"\",\"defects\":[]}",
    "duration_minutes": "0",
    "sequence_order": "2",
    "department": "flexo_printing",
    "previous_job_id": "1",
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "timer_accumulated_seconds": 3,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24T18:03:25.635Z",
        "timer_events": [
            {
                "type": "start",
                "at": "2026-07-24T18:03:25.635Z"
            },
            {
                "type": "end",
                "at": "2026-07-24 20:03:28"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 20:03:58",
        "timer_work_segments": [
            {
                "from": "2026-07-24 20:03:25",
                "to": "2026-07-24 20:03:28",
                "seconds": 3
            }
        ],
        "mkd_job_sl_no": "",
        "job_date": "2026-07-23",
        "job_name": "Mewa laya",
        "die": "FlatBed",
        "plate_no": "1058",
        "material_company": "RAJ PAPER",
        "material_name": "CHROMO",
        "order_mtr": 2000,
        "order_qty": 26247,
        "reel_no_c1": "",
        "reel_no_c2": "",
        "width_c1": 1000,
        "width_c2": 1000,
        "length_c1": 3000,
        "length_c2": 3000,
        "label_size": "90mm X 149mm",
        "repeat_mm": "152.4",
        "direction": "Left First",
        "actual_qty": 26000,
        "electricity": "10",
        "time_spent": "00:00:03",
        "prepared_by": "System Admin",
        "filled_by": "System Admin",
        "defects_text": "",
        "physical_print_photo_url": "",
        "physical_print_photo_path": "",
        "total_wastage_meters": "",
        "colour_lanes": [
            "Cyan",
            "Magenta",
            "Yellow",
            "Black",
            "None",
            "None",
            "None",
            "None"
        ],
        "anilox_lanes": [
            "None",
            "None",
            "None",
            "None",
            "None",
            "None",
            "None",
            "None"
        ],
        "material_rows": [
            {
                "idx": 1,
                "roll_no": "SLC/2026/0232-A",
                "parent_roll_no": "SLC/2026/0232",
                "slitted_width": "500",
                "slitted_length": "3000",
                "material_company": "RAJ PAPER",
                "material_name": "CHROMO",
                "order_mtr": "2000",
                "order_qty": "26247",
                "color_match_status": "Matched",
                "wastage_meters": ""
            },
            {
                "idx": 2,
                "roll_no": "SLC/2026/0232-B",
                "parent_roll_no": "SLC/2026/0232",
                "slitted_width": "500",
                "slitted_length": "3000",
                "material_company": "RAJ PAPER",
                "material_name": "CHROMO",
                "order_mtr": "2000",
                "order_qty": "26247",
                "color_match_status": "Matched",
                "wastage_meters": ""
            }
        ],
        "roll_wastage_rows": [
            {
                "idx": 1,
                "roll_no": "SLC/2026/0232-A",
                "parent_roll_no": "SLC/2026/0232",
                "slitted_width": "500",
                "slitted_length": "3000",
                "material_company": "RAJ PAPER",
                "material_name": "CHROMO",
                "order_mtr": "2000",
                "order_qty": "26247",
                "color_match_status": "Matched",
                "wastage_meters": ""
            },
            {
                "idx": 2,
                "roll_no": "SLC/2026/0232-B",
                "parent_roll_no": "SLC/2026/0232",
                "slitted_width": "500",
                "slitted_length": "3000",
                "material_company": "RAJ PAPER",
                "material_name": "CHROMO",
                "order_mtr": "2000",
                "order_qty": "26247",
                "color_match_status": "Matched",
                "wastage_meters": ""
            }
        ],
        "color_anilox_rows": [
            {
                "lane": 1,
                "color_code": "Cyan",
                "color_name": "Cyan",
                "anilox_value": "None",
                "anilox_custom": ""
            },
            {
                "lane": 2,
                "color_code": "Magenta",
                "color_name": "Magenta",
                "anilox_value": "None",
                "anilox_custom": ""
            },
            {
                "lane": 3,
                "color_code": "Yellow",
                "color_name": "Yellow",
                "anilox_value": "None",
                "anilox_custom": ""
            },
            {
                "lane": 4,
                "color_code": "Black",
                "color_name": "Black",
                "anilox_value": "None",
                "anilox_custom": ""
            },
            {
                "lane": 5,
                "color_code": "None",
                "color_name": "None",
                "anilox_value": "None",
                "anilox_custom": ""
            },
            {
                "lane": 6,
                "color_code": "None",
                "color_name": "None",
                "anilox_value": "None",
                "anilox_custom": ""
            },
            {
                "lane": 7,
                "color_code": "None",
                "color_name": "None",
                "anilox_value": "None",
                "anilox_custom": ""
            },
            {
                "lane": 8,
                "color_code": "None",
                "color_name": "None",
                "anilox_value": "None",
                "anilox_custom": ""
            }
        ],
        "ink_colors": "",
        "cylinder_ref": "",
        "impression_count": "",
        "print_speed": "",
        "color_match_status": "Matched",
        "wastage_meters": "",
        "operator_notes": "",
        "defects": []
    }
}
```

### Job ID #3 (DCT/2026/0001 - flatbed)
```json
{
    "id": "3",
    "job_no": "DCT/2026/0001",
    "planning_id": "1",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0232",
    "job_type": "Finishing",
    "status": "Completed",
    "started_at": "2026-07-24 23:34:25",
    "completed_at": "2026-07-24 23:34:45",
    "operator_id": null,
    "notes": "Die-cutting queued from Flexo | Plan: PLN/2026/0001 | Flexo: FLX/2026/0001 | Job name: Mewa laya",
    "created_at": "2026-07-24 22:33:41",
    "updated_at": "2026-07-24 23:34:45",
    "extra_data": "{\"timer_accumulated_seconds\":2,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24 20:04:25\",\"timer_events\":[{\"type\":\"start\",\"at\":\"2026-07-24 20:04:25\"},{\"type\":\"end\",\"at\":\"2026-07-24 20:04:27\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 20:04:45\",\"timer_work_segments\":[{\"from\":\"2026-07-24 20:04:25\",\"to\":\"2026-07-24 20:04:27\",\"seconds\":2}],\"die_cutting_total_qty_pcs\":\"26000\",\"die_cutting_wastage_pcs\":\"0\",\"die_cutting_wastage_mtr\":\"0\",\"die_cutting_notes_text\":\"\",\"voice_language\":\"en-IN\",\"die_cutting_printed_roll_length_mtr\":\"3000.00\",\"die_cutting_photo_path\":\"\",\"die_cutting_voice_note_path\":\"\",\"die_cutting_submitted_at\":\"2026-07-24T18:04:44.248Z\"}",
    "duration_minutes": "0",
    "sequence_order": "3",
    "department": "flatbed",
    "previous_job_id": "2",
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "timer_accumulated_seconds": 2,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24 20:04:25",
        "timer_events": [
            {
                "type": "start",
                "at": "2026-07-24 20:04:25"
            },
            {
                "type": "end",
                "at": "2026-07-24 20:04:27"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 20:04:45",
        "timer_work_segments": [
            {
                "from": "2026-07-24 20:04:25",
                "to": "2026-07-24 20:04:27",
                "seconds": 2
            }
        ],
        "die_cutting_total_qty_pcs": "26000",
        "die_cutting_wastage_pcs": "0",
        "die_cutting_wastage_mtr": "0",
        "die_cutting_notes_text": "",
        "voice_language": "en-IN",
        "die_cutting_printed_roll_length_mtr": "3000.00",
        "die_cutting_photo_path": "",
        "die_cutting_voice_note_path": "",
        "die_cutting_submitted_at": "2026-07-24T18:04:44.248Z"
    }
}
```

### Job ID #4 (LSL/2026/0001 - label_slitting)
```json
{
    "id": "4",
    "job_no": "LSL/2026/0001",
    "planning_id": "1",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0232",
    "job_type": "Finishing",
    "status": "Completed",
    "started_at": "2026-07-24 23:38:25",
    "completed_at": "2026-07-24 23:38:56",
    "operator_id": null,
    "notes": "Label slitting released from upstream | Plan: PLN/2026/0001 | Jumbo: JMB/2026/0001 | Flexo: FLX/2026/0001 | Die-Cut: DCT/2026/0001 | Label: LSL/2026/0001 | Job name: DCT/2026/0001 (Flatbed)",
    "created_at": "2026-07-24 22:33:42",
    "updated_at": "2026-07-24 23:38:56",
    "extra_data": "{\"timer_accumulated_seconds\":2,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24 20:08:25\",\"timer_events\":[{\"type\":\"start\",\"at\":\"2026-07-24 20:08:25\"},{\"type\":\"end\",\"at\":\"2026-07-24 20:08:27\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 20:08:56\",\"timer_work_segments\":[{\"from\":\"2026-07-24 20:08:25\",\"to\":\"2026-07-24 20:08:27\",\"seconds\":2}],\"label_slitting_qty_in_roll\":\"500\",\"label_slitting_total_roll\":\"52\",\"label_slitting_total_production\":\"26000\",\"label_slitting_wastage_percentage\":\"\",\"label_slitting_notes_text\":\"\",\"label_slitting_photo_path\":\"\",\"label_slitting_voice_note_path\":\"\",\"label_slitting_submitted_at\":\"2026-07-24T18:08:55.235Z\",\"assigned_child_rolls\":[{\"roll_no\":\"SLC\\/2026\\/0232-A\",\"width_mm\":500,\"length_mtr\":3000,\"paper_type\":\"CHROMO\",\"company\":\"RAJ PAPER\",\"gsm\":184,\"status\":\"Job Assign\",\"production_qty\":500,\"available_qty\":500},{\"roll_no\":\"SLC\\/2026\\/0232-B\",\"width_mm\":500,\"length_mtr\":3000,\"paper_type\":\"CHROMO\",\"company\":\"RAJ PAPER\",\"gsm\":184,\"status\":\"Job Assign\",\"production_qty\":500,\"available_qty\":500}]}",
    "duration_minutes": "0",
    "sequence_order": "4",
    "department": "label_slitting",
    "previous_job_id": "3",
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "timer_accumulated_seconds": 2,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24 20:08:25",
        "timer_events": [
            {
                "type": "start",
                "at": "2026-07-24 20:08:25"
            },
            {
                "type": "end",
                "at": "2026-07-24 20:08:27"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 20:08:56",
        "timer_work_segments": [
            {
                "from": "2026-07-24 20:08:25",
                "to": "2026-07-24 20:08:27",
                "seconds": 2
            }
        ],
        "label_slitting_qty_in_roll": "500",
        "label_slitting_total_roll": "52",
        "label_slitting_total_production": "26000",
        "label_slitting_wastage_percentage": "",
        "label_slitting_notes_text": "",
        "label_slitting_photo_path": "",
        "label_slitting_voice_note_path": "",
        "label_slitting_submitted_at": "2026-07-24T18:08:55.235Z",
        "assigned_child_rolls": [
            {
                "roll_no": "SLC/2026/0232-A",
                "width_mm": 500,
                "length_mtr": 3000,
                "paper_type": "CHROMO",
                "company": "RAJ PAPER",
                "gsm": 184,
                "status": "Job Assign",
                "production_qty": 500,
                "available_qty": 500
            },
            {
                "roll_no": "SLC/2026/0232-B",
                "width_mm": 500,
                "length_mtr": 3000,
                "paper_type": "CHROMO",
                "company": "RAJ PAPER",
                "gsm": 184,
                "status": "Job Assign",
                "production_qty": 500,
                "available_qty": 500
            }
        ]
    }
}
```

### Job ID #6 (SLT/2026/0001 - jumbo_slitting)
```json
{
    "id": "6",
    "job_no": "SLT/2026/0001",
    "planning_id": "2",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0233",
    "job_type": "Slitting",
    "status": "Closed",
    "started_at": "2026-07-24 23:18:29",
    "completed_at": "2026-07-24 23:18:36",
    "operator_id": null,
    "notes": "Jumbo grouped slitting job | Plan: PLN-BAR/2026/0001 | JMB: SLT/2026/0001 | Job Name : Barcode | 100mm X 150mm | 2 Ups",
    "created_at": "2026-07-24 22:34:26",
    "updated_at": "2026-07-24 23:18:36",
    "extra_data": "{\"plan_no\":\"PLN-BAR\\/2026\\/0001\",\"parent_roll\":\"SLC\\/2026\\/0233\",\"parent_details\":{\"roll_no\":\"SLC\\/2026\\/0233\",\"company\":\"RAJ PAPER\",\"paper_type\":\"CHROMO\",\"width_mm\":1000,\"length_mtr\":2000,\"gsm\":75,\"weight_kg\":0,\"sqm\":2000,\"original_status\":\"Main\",\"remarks\":\"\"},\"child_rolls\":[{\"roll_no\":\"SLC\\/2026\\/0233-A\",\"parent_roll_no\":\"SLC\\/2026\\/0233\",\"width\":500,\"length\":2000,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":2,\"plan_no\":\"PLN-BAR\\/2026\\/0001\",\"allocation_id\":2,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, BarCode, Label Slitting, Packaging, Dispatch\",\"job_no\":\"PLN-BAR\\/2026\\/0001\",\"job_name\":\"Barcode | 100mm X 150mm | 2 Ups\",\"company\":\"RAJ PAPER\",\"paper_type\":\"CHROMO\",\"gsm\":75,\"weight_kg\":0,\"sqm\":1000,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"},{\"roll_no\":\"SLC\\/2026\\/0233-B\",\"parent_roll_no\":\"SLC\\/2026\\/0233\",\"width\":500,\"length\":2000,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":2,\"plan_no\":\"PLN-BAR\\/2026\\/0001\",\"allocation_id\":2,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, BarCode, Label Slitting, Packaging, Dispatch\",\"job_no\":\"PLN-BAR\\/2026\\/0001\",\"job_name\":\"Barcode | 100mm X 150mm | 2 Ups\",\"company\":\"RAJ PAPER\",\"paper_type\":\"CHROMO\",\"gsm\":75,\"weight_kg\":0,\"sqm\":1000,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"}],\"stock_rolls\":[],\"total_roll_count\":3,\"total_qty_mtr\":4000,\"material\":\"CHROMO\",\"paper_combined\":false,\"batch_no\":\"BAT\\/2026\\/0002\",\"machine\":\"Jumbo Sliting\",\"operator_name\":\"System Admin\",\"flexo_request_accept_flow\":false,\"flexo_target_department\":\"jumbo slitting\",\"flexo_request_id\":0,\"flexo_job_id\":0,\"flexo_task_index\":-1,\"plan_allocations\":[{\"allocation_id\":2,\"planning_id\":2,\"plan_no\":\"PLN-BAR\\/2026\\/0001\",\"job_name\":\"Barcode | 100mm X 150mm | 2 Ups\",\"job_size\":\"100mm X 150mm\",\"department_route\":\"Jumbo Slitting, BarCode, Label Slitting, Packaging, Dispatch\",\"allocated_width_mm\":1000,\"allocated_length_mtr\":4000,\"allocation_sequence\":1}],\"timer_accumulated_seconds\":2,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24T17:48:29.386Z\",\"timer_events\":[{\"type\":\"end\",\"at\":\"2026-07-24 19:48:31\"},{\"type\":\"end\",\"at\":\"2026-07-24 19:48:31\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 19:48:36\",\"timer_work_segments\":[{\"from\":\"2026-07-24 19:48:29\",\"to\":\"2026-07-24 19:48:31\",\"seconds\":2}],\"wastage_kg\":\"1\",\"operator_notes\":\"\"}",
    "duration_minutes": "0",
    "sequence_order": "1",
    "department": "jumbo_slitting",
    "previous_job_id": null,
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "plan_no": "PLN-BAR/2026/0001",
        "parent_roll": "SLC/2026/0233",
        "parent_details": {
            "roll_no": "SLC/2026/0233",
            "company": "RAJ PAPER",
            "paper_type": "CHROMO",
            "width_mm": 1000,
            "length_mtr": 2000,
            "gsm": 75,
            "weight_kg": 0,
            "sqm": 2000,
            "original_status": "Main",
            "remarks": ""
        },
        "child_rolls": [
            {
                "roll_no": "SLC/2026/0233-A",
                "parent_roll_no": "SLC/2026/0233",
                "width": 500,
                "length": 2000,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 2,
                "plan_no": "PLN-BAR/2026/0001",
                "allocation_id": 2,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, BarCode, Label Slitting, Packaging, Dispatch",
                "job_no": "PLN-BAR/2026/0001",
                "job_name": "Barcode | 100mm X 150mm | 2 Ups",
                "company": "RAJ PAPER",
                "paper_type": "CHROMO",
                "gsm": 75,
                "weight_kg": 0,
                "sqm": 1000,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            },
            {
                "roll_no": "SLC/2026/0233-B",
                "parent_roll_no": "SLC/2026/0233",
                "width": 500,
                "length": 2000,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 2,
                "plan_no": "PLN-BAR/2026/0001",
                "allocation_id": 2,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, BarCode, Label Slitting, Packaging, Dispatch",
                "job_no": "PLN-BAR/2026/0001",
                "job_name": "Barcode | 100mm X 150mm | 2 Ups",
                "company": "RAJ PAPER",
                "paper_type": "CHROMO",
                "gsm": 75,
                "weight_kg": 0,
                "sqm": 1000,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            }
        ],
        "stock_rolls": [],
        "total_roll_count": 3,
        "total_qty_mtr": 4000,
        "material": "CHROMO",
        "paper_combined": false,
        "batch_no": "BAT/2026/0002",
        "machine": "Jumbo Sliting",
        "operator_name": "System Admin",
        "flexo_request_accept_flow": false,
        "flexo_target_department": "jumbo slitting",
        "flexo_request_id": 0,
        "flexo_job_id": 0,
        "flexo_task_index": -1,
        "plan_allocations": [
            {
                "allocation_id": 2,
                "planning_id": 2,
                "plan_no": "PLN-BAR/2026/0001",
                "job_name": "Barcode | 100mm X 150mm | 2 Ups",
                "job_size": "100mm X 150mm",
                "department_route": "Jumbo Slitting, BarCode, Label Slitting, Packaging, Dispatch",
                "allocated_width_mm": 1000,
                "allocated_length_mtr": 4000,
                "allocation_sequence": 1
            }
        ],
        "timer_accumulated_seconds": 2,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24T17:48:29.386Z",
        "timer_events": [
            {
                "type": "end",
                "at": "2026-07-24 19:48:31"
            },
            {
                "type": "end",
                "at": "2026-07-24 19:48:31"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 19:48:36",
        "timer_work_segments": [
            {
                "from": "2026-07-24 19:48:29",
                "to": "2026-07-24 19:48:31",
                "seconds": 2
            }
        ],
        "wastage_kg": "1",
        "operator_notes": ""
    }
}
```

### Job ID #7 (BRC-BAR/2026/0001 - barcode)
```json
{
    "id": "7",
    "job_no": "BRC-BAR/2026/0001",
    "planning_id": "2",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0233",
    "job_type": "Finishing",
    "status": "Completed",
    "started_at": "2026-07-24 23:37:28",
    "completed_at": "2026-07-24 23:37:40",
    "operator_id": null,
    "notes": "Barcode job queued from upstream | Plan: PLN-BAR/2026/0001 | From: SLT/2026/0001 | Barcode: BRC-BAR/2026/0001 | Job name: Barcode | 100mm X 150mm | 2 Ups",
    "created_at": "2026-07-24 22:34:26",
    "updated_at": "2026-07-24 23:37:40",
    "extra_data": "{\"assigned_child_rolls\":[{\"roll_no\":\"SLC\\/2026\\/0233-A\",\"parent_roll_no\":\"SLC\\/2026\\/0233\",\"width_mm\":500,\"length_mtr\":2000,\"paper_type\":\"CHROMO\",\"company\":\"RAJ PAPER\",\"gsm\":75,\"status\":\"Slitting\",\"job_no\":\"PLN-BAR\\/2026\\/0001\",\"job_name\":\"Barcode | 100mm X 150mm | 2 Ups\"},{\"roll_no\":\"SLC\\/2026\\/0233-B\",\"parent_roll_no\":\"SLC\\/2026\\/0233\",\"width_mm\":500,\"length_mtr\":2000,\"paper_type\":\"CHROMO\",\"company\":\"RAJ PAPER\",\"gsm\":75,\"status\":\"Slitting\",\"job_no\":\"PLN-BAR\\/2026\\/0001\",\"job_name\":\"Barcode | 100mm X 150mm | 2 Ups\"}],\"assigned_child_roll_count\":2,\"assigned_parent_roll_no\":\"SLC\\/2026\\/0233\",\"assigned_last_batch_no\":\"BAT\\/2026\\/0002\",\"assigned_updated_at\":\"2026-07-24T19:04:26+02:00\",\"machine\":\"\",\"plan_no\":\"PLN-BAR\\/2026\\/0001\",\"direct_barcode_bypass\":false,\"timer_accumulated_seconds\":1,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24 20:07:28\",\"timer_events\":[{\"type\":\"start\",\"at\":\"2026-07-24 20:07:28\"},{\"type\":\"end\",\"at\":\"2026-07-24 20:07:29\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 20:07:40\",\"timer_work_segments\":[{\"from\":\"2026-07-24 20:07:28\",\"to\":\"2026-07-24 20:07:29\",\"seconds\":1}],\"die_cutting_total_qty_pcs\":\"15000\",\"die_cutting_wastage_pcs\":\"0\",\"die_cutting_wastage_mtr\":\"0\",\"die_cutting_notes_text\":\"\",\"voice_language\":\"en-IN\",\"die_cutting_printed_roll_length_mtr\":\"2000.00\",\"die_cutting_photo_path\":\"\",\"die_cutting_voice_note_path\":\"\",\"die_cutting_submitted_at\":\"2026-07-24T18:07:39.388Z\"}",
    "duration_minutes": "0",
    "sequence_order": "3",
    "department": "barcode",
    "previous_job_id": "6",
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "assigned_child_rolls": [
            {
                "roll_no": "SLC/2026/0233-A",
                "parent_roll_no": "SLC/2026/0233",
                "width_mm": 500,
                "length_mtr": 2000,
                "paper_type": "CHROMO",
                "company": "RAJ PAPER",
                "gsm": 75,
                "status": "Slitting",
                "job_no": "PLN-BAR/2026/0001",
                "job_name": "Barcode | 100mm X 150mm | 2 Ups"
            },
            {
                "roll_no": "SLC/2026/0233-B",
                "parent_roll_no": "SLC/2026/0233",
                "width_mm": 500,
                "length_mtr": 2000,
                "paper_type": "CHROMO",
                "company": "RAJ PAPER",
                "gsm": 75,
                "status": "Slitting",
                "job_no": "PLN-BAR/2026/0001",
                "job_name": "Barcode | 100mm X 150mm | 2 Ups"
            }
        ],
        "assigned_child_roll_count": 2,
        "assigned_parent_roll_no": "SLC/2026/0233",
        "assigned_last_batch_no": "BAT/2026/0002",
        "assigned_updated_at": "2026-07-24T19:04:26+02:00",
        "machine": "",
        "plan_no": "PLN-BAR/2026/0001",
        "direct_barcode_bypass": false,
        "timer_accumulated_seconds": 1,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24 20:07:28",
        "timer_events": [
            {
                "type": "start",
                "at": "2026-07-24 20:07:28"
            },
            {
                "type": "end",
                "at": "2026-07-24 20:07:29"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 20:07:40",
        "timer_work_segments": [
            {
                "from": "2026-07-24 20:07:28",
                "to": "2026-07-24 20:07:29",
                "seconds": 1
            }
        ],
        "die_cutting_total_qty_pcs": "15000",
        "die_cutting_wastage_pcs": "0",
        "die_cutting_wastage_mtr": "0",
        "die_cutting_notes_text": "",
        "voice_language": "en-IN",
        "die_cutting_printed_roll_length_mtr": "2000.00",
        "die_cutting_photo_path": "",
        "die_cutting_voice_note_path": "",
        "die_cutting_submitted_at": "2026-07-24T18:07:39.388Z"
    }
}
```

### Job ID #8 (LSL/2026/0002 - label_slitting)
```json
{
    "id": "8",
    "job_no": "LSL/2026/0002",
    "planning_id": "2",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0233",
    "job_type": "Finishing",
    "status": "Completed",
    "started_at": "2026-07-24 23:38:59",
    "completed_at": "2026-07-24 23:39:11",
    "operator_id": null,
    "notes": "Label slitting queued from upstream | Plan: PLN-BAR/2026/0001 | Jumbo: SLT/2026/0001 | Flexo: N/A | Die-Cut: N/A | Label: LSL/2026/0002 | Job name: Barcode | 100mm X 150mm | 2 Ups",
    "created_at": "2026-07-24 22:34:26",
    "updated_at": "2026-07-24 23:39:11",
    "extra_data": "{\"timer_accumulated_seconds\":2,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24 20:08:59\",\"timer_events\":[{\"type\":\"start\",\"at\":\"2026-07-24 20:08:59\"},{\"type\":\"end\",\"at\":\"2026-07-24 20:09:01\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 20:09:11\",\"timer_work_segments\":[{\"from\":\"2026-07-24 20:08:59\",\"to\":\"2026-07-24 20:09:01\",\"seconds\":2}],\"label_slitting_qty_in_roll\":\"250\",\"label_slitting_total_roll\":\"60\",\"label_slitting_total_production\":\"15000\",\"label_slitting_wastage_percentage\":\"\",\"label_slitting_notes_text\":\"\",\"label_slitting_photo_path\":\"\",\"label_slitting_voice_note_path\":\"\",\"label_slitting_submitted_at\":\"2026-07-24T18:09:09.488Z\",\"assigned_child_rolls\":[{\"roll_no\":\"SLC\\/2026\\/0233-A\",\"width_mm\":500,\"length_mtr\":2000,\"paper_type\":\"CHROMO\",\"company\":\"RAJ PAPER\",\"gsm\":75,\"status\":\"Job Assign\",\"production_qty\":250,\"available_qty\":250},{\"roll_no\":\"SLC\\/2026\\/0233-B\",\"width_mm\":500,\"length_mtr\":2000,\"paper_type\":\"CHROMO\",\"company\":\"RAJ PAPER\",\"gsm\":75,\"status\":\"Job Assign\",\"production_qty\":250,\"available_qty\":250}]}",
    "duration_minutes": "0",
    "sequence_order": "4",
    "department": "label_slitting",
    "previous_job_id": null,
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "timer_accumulated_seconds": 2,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24 20:08:59",
        "timer_events": [
            {
                "type": "start",
                "at": "2026-07-24 20:08:59"
            },
            {
                "type": "end",
                "at": "2026-07-24 20:09:01"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 20:09:11",
        "timer_work_segments": [
            {
                "from": "2026-07-24 20:08:59",
                "to": "2026-07-24 20:09:01",
                "seconds": 2
            }
        ],
        "label_slitting_qty_in_roll": "250",
        "label_slitting_total_roll": "60",
        "label_slitting_total_production": "15000",
        "label_slitting_wastage_percentage": "",
        "label_slitting_notes_text": "",
        "label_slitting_photo_path": "",
        "label_slitting_voice_note_path": "",
        "label_slitting_submitted_at": "2026-07-24T18:09:09.488Z",
        "assigned_child_rolls": [
            {
                "roll_no": "SLC/2026/0233-A",
                "width_mm": 500,
                "length_mtr": 2000,
                "paper_type": "CHROMO",
                "company": "RAJ PAPER",
                "gsm": 75,
                "status": "Job Assign",
                "production_qty": 250,
                "available_qty": 250
            },
            {
                "roll_no": "SLC/2026/0233-B",
                "width_mm": 500,
                "length_mtr": 2000,
                "paper_type": "CHROMO",
                "company": "RAJ PAPER",
                "gsm": 75,
                "status": "Job Assign",
                "production_qty": 250,
                "available_qty": 250
            }
        ]
    }
}
```

### Job ID #10 (SLT/2026/0002 - jumbo_slitting)
```json
{
    "id": "10",
    "job_no": "SLT/2026/0002",
    "planning_id": "3",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0231",
    "job_type": "Slitting",
    "status": "Closed",
    "started_at": "2026-07-24 23:18:52",
    "completed_at": "2026-07-24 23:18:58",
    "operator_id": null,
    "notes": "Jumbo grouped slitting job | Plan: PLN-POS/2026/0001 | JMB: SLT/2026/0002 | Job Name : POS-7950",
    "created_at": "2026-07-24 22:35:38",
    "updated_at": "2026-07-24 23:18:58",
    "extra_data": "{\"plan_no\":\"PLN-POS\\/2026\\/0001\",\"parent_roll\":\"SLC\\/2026\\/0231\",\"parent_details\":{\"roll_no\":\"SLC\\/2026\\/0231\",\"company\":\"RAJ PAPER\",\"paper_type\":\"THERMAL PAPER\",\"width_mm\":1000,\"length_mtr\":2000,\"gsm\":184,\"weight_kg\":0,\"sqm\":2000,\"original_status\":\"Main\",\"remarks\":\"\"},\"child_rolls\":[{\"roll_no\":\"SLC\\/2026\\/0231-A\",\"parent_roll_no\":\"SLC\\/2026\\/0231\",\"width\":500,\"length\":2000,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":3,\"plan_no\":\"PLN-POS\\/2026\\/0001\",\"allocation_id\":3,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, PaperRoll, Packaging, Dispatch\",\"job_no\":\"PLN-POS\\/2026\\/0001\",\"job_name\":\"POS-7950\",\"company\":\"RAJ PAPER\",\"paper_type\":\"THERMAL PAPER\",\"gsm\":184,\"weight_kg\":0,\"sqm\":1000,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"},{\"roll_no\":\"SLC\\/2026\\/0231-B\",\"parent_roll_no\":\"SLC\\/2026\\/0231\",\"width\":500,\"length\":2000,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":3,\"plan_no\":\"PLN-POS\\/2026\\/0001\",\"allocation_id\":3,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, PaperRoll, Packaging, Dispatch\",\"job_no\":\"PLN-POS\\/2026\\/0001\",\"job_name\":\"POS-7950\",\"company\":\"RAJ PAPER\",\"paper_type\":\"THERMAL PAPER\",\"gsm\":184,\"weight_kg\":0,\"sqm\":1000,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"}],\"stock_rolls\":[],\"total_roll_count\":3,\"total_qty_mtr\":4000,\"material\":\"THERMAL PAPER\",\"paper_combined\":false,\"batch_no\":\"BAT\\/2026\\/0003\",\"machine\":\"Jumbo Sliting\",\"operator_name\":\"System Admin\",\"flexo_request_accept_flow\":false,\"flexo_target_department\":\"jumbo slitting\",\"flexo_request_id\":0,\"flexo_job_id\":0,\"flexo_task_index\":-1,\"plan_allocations\":[{\"allocation_id\":3,\"planning_id\":3,\"plan_no\":\"PLN-POS\\/2026\\/0001\",\"job_name\":\"POS-7950\",\"job_size\":\"35\",\"department_route\":\"Jumbo Slitting, PaperRoll, Packaging, Dispatch\",\"allocated_width_mm\":1000,\"allocated_length_mtr\":4000,\"allocation_sequence\":1}],\"timer_accumulated_seconds\":2,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24T17:48:52.888Z\",\"timer_events\":[{\"type\":\"end\",\"at\":\"2026-07-24 19:48:54\"},{\"type\":\"end\",\"at\":\"2026-07-24 19:48:54\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 19:48:58\",\"timer_work_segments\":[{\"from\":\"2026-07-24 19:48:52\",\"to\":\"2026-07-24 19:48:54\",\"seconds\":2}],\"wastage_kg\":\"1\",\"operator_notes\":\"\"}",
    "duration_minutes": "0",
    "sequence_order": "1",
    "department": "jumbo_slitting",
    "previous_job_id": null,
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "plan_no": "PLN-POS/2026/0001",
        "parent_roll": "SLC/2026/0231",
        "parent_details": {
            "roll_no": "SLC/2026/0231",
            "company": "RAJ PAPER",
            "paper_type": "THERMAL PAPER",
            "width_mm": 1000,
            "length_mtr": 2000,
            "gsm": 184,
            "weight_kg": 0,
            "sqm": 2000,
            "original_status": "Main",
            "remarks": ""
        },
        "child_rolls": [
            {
                "roll_no": "SLC/2026/0231-A",
                "parent_roll_no": "SLC/2026/0231",
                "width": 500,
                "length": 2000,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 3,
                "plan_no": "PLN-POS/2026/0001",
                "allocation_id": 3,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, PaperRoll, Packaging, Dispatch",
                "job_no": "PLN-POS/2026/0001",
                "job_name": "POS-7950",
                "company": "RAJ PAPER",
                "paper_type": "THERMAL PAPER",
                "gsm": 184,
                "weight_kg": 0,
                "sqm": 1000,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            },
            {
                "roll_no": "SLC/2026/0231-B",
                "parent_roll_no": "SLC/2026/0231",
                "width": 500,
                "length": 2000,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 3,
                "plan_no": "PLN-POS/2026/0001",
                "allocation_id": 3,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, PaperRoll, Packaging, Dispatch",
                "job_no": "PLN-POS/2026/0001",
                "job_name": "POS-7950",
                "company": "RAJ PAPER",
                "paper_type": "THERMAL PAPER",
                "gsm": 184,
                "weight_kg": 0,
                "sqm": 1000,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            }
        ],
        "stock_rolls": [],
        "total_roll_count": 3,
        "total_qty_mtr": 4000,
        "material": "THERMAL PAPER",
        "paper_combined": false,
        "batch_no": "BAT/2026/0003",
        "machine": "Jumbo Sliting",
        "operator_name": "System Admin",
        "flexo_request_accept_flow": false,
        "flexo_target_department": "jumbo slitting",
        "flexo_request_id": 0,
        "flexo_job_id": 0,
        "flexo_task_index": -1,
        "plan_allocations": [
            {
                "allocation_id": 3,
                "planning_id": 3,
                "plan_no": "PLN-POS/2026/0001",
                "job_name": "POS-7950",
                "job_size": "35",
                "department_route": "Jumbo Slitting, PaperRoll, Packaging, Dispatch",
                "allocated_width_mm": 1000,
                "allocated_length_mtr": 4000,
                "allocation_sequence": 1
            }
        ],
        "timer_accumulated_seconds": 2,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24T17:48:52.888Z",
        "timer_events": [
            {
                "type": "end",
                "at": "2026-07-24 19:48:54"
            },
            {
                "type": "end",
                "at": "2026-07-24 19:48:54"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 19:48:58",
        "timer_work_segments": [
            {
                "from": "2026-07-24 19:48:52",
                "to": "2026-07-24 19:48:54",
                "seconds": 2
            }
        ],
        "wastage_kg": "1",
        "operator_notes": ""
    }
}
```

### Job ID #11 (PRL-POS/2026/0001 - paperroll)
```json
{
    "id": "11",
    "job_no": "PRL-POS/2026/0001",
    "planning_id": "3",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0231",
    "job_type": "Finishing",
    "status": "Completed",
    "started_at": null,
    "completed_at": "2026-07-24 23:35:40",
    "operator_id": null,
    "notes": "PaperRoll job queued from upstream | Plan: PLN-POS/2026/0001 | From: SLT/2026/0002 | PaperRoll: PRL-POS/2026/0001 | Job name: POS-7950",
    "created_at": "2026-07-24 22:35:38",
    "updated_at": "2026-07-24 23:35:40",
    "extra_data": "{\"assigned_child_rolls\":[{\"roll_no\":\"SLC/2026/0231-A\",\"parent_roll_no\":\"SLC/2026/0231\",\"width_mm\":500,\"length_mtr\":2000,\"paper_type\":\"THERMAL PAPER\",\"company\":\"RAJ PAPER\",\"gsm\":184,\"status\":\"Slitting\",\"job_no\":\"PLN-POS/2026/0001\",\"job_name\":\"POS-7950\"},{\"roll_no\":\"SLC/2026/0231-B\",\"parent_roll_no\":\"SLC/2026/0231\",\"width_mm\":500,\"length_mtr\":2000,\"paper_type\":\"THERMAL PAPER\",\"company\":\"RAJ PAPER\",\"gsm\":184,\"status\":\"Slitting\",\"job_no\":\"PLN-POS/2026/0001\",\"job_name\":\"POS-7950\"}],\"assigned_child_roll_count\":2,\"assigned_parent_roll_no\":\"SLC/2026/0231\",\"assigned_last_batch_no\":\"BAT/2026/0003\",\"assigned_updated_at\":\"2026-07-24T19:05:38+02:00\",\"machine\":\"\",\"plan_no\":\"PLN-POS/2026/0001\",\"direct_paperroll_bypass\":false}",
    "duration_minutes": null,
    "sequence_order": "4",
    "department": "paperroll",
    "previous_job_id": "10",
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "assigned_child_rolls": [
            {
                "roll_no": "SLC/2026/0231-A",
                "parent_roll_no": "SLC/2026/0231",
                "width_mm": 500,
                "length_mtr": 2000,
                "paper_type": "THERMAL PAPER",
                "company": "RAJ PAPER",
                "gsm": 184,
                "status": "Slitting",
                "job_no": "PLN-POS/2026/0001",
                "job_name": "POS-7950"
            },
            {
                "roll_no": "SLC/2026/0231-B",
                "parent_roll_no": "SLC/2026/0231",
                "width_mm": 500,
                "length_mtr": 2000,
                "paper_type": "THERMAL PAPER",
                "company": "RAJ PAPER",
                "gsm": 184,
                "status": "Slitting",
                "job_no": "PLN-POS/2026/0001",
                "job_name": "POS-7950"
            }
        ],
        "assigned_child_roll_count": 2,
        "assigned_parent_roll_no": "SLC/2026/0231",
        "assigned_last_batch_no": "BAT/2026/0003",
        "assigned_updated_at": "2026-07-24T19:05:38+02:00",
        "machine": "",
        "plan_no": "PLN-POS/2026/0001",
        "direct_paperroll_bypass": false
    }
}
```

### Job ID #12 (POS-POS/2026/0001 - pos)
```json
{
    "id": "12",
    "job_no": "POS-POS/2026/0001",
    "planning_id": "3",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0231",
    "job_type": "Finishing",
    "status": "Completed",
    "started_at": "2026-07-24 23:35:15",
    "completed_at": "2026-07-24 23:35:40",
    "operator_id": null,
    "notes": "POS Roll job queued from upstream | Plan: PLN-POS/2026/0001 | From: SLT/2026/0002 | POS: POS-POS/2026/0001 | Job name: POS-7950",
    "created_at": "2026-07-24 22:35:38",
    "updated_at": "2026-07-24 23:35:40",
    "extra_data": "{\"assigned_child_rolls\":[{\"roll_no\":\"SLC\\/2026\\/0231-A\",\"parent_roll_no\":\"SLC\\/2026\\/0231\",\"width_mm\":500,\"length_mtr\":2000,\"paper_type\":\"THERMAL PAPER\",\"company\":\"RAJ PAPER\",\"gsm\":184,\"status\":\"Slitting\",\"job_no\":\"PLN-POS\\/2026\\/0001\",\"job_name\":\"POS-7950\"},{\"roll_no\":\"SLC\\/2026\\/0231-B\",\"parent_roll_no\":\"SLC\\/2026\\/0231\",\"width_mm\":500,\"length_mtr\":2000,\"paper_type\":\"THERMAL PAPER\",\"company\":\"RAJ PAPER\",\"gsm\":184,\"status\":\"Slitting\",\"job_no\":\"PLN-POS\\/2026\\/0001\",\"job_name\":\"POS-7950\"}],\"assigned_child_roll_count\":2,\"assigned_parent_roll_no\":\"SLC\\/2026\\/0231\",\"assigned_last_batch_no\":\"BAT\\/2026\\/0003\",\"assigned_updated_at\":\"2026-07-24T19:05:38+02:00\",\"machine\":\"\",\"plan_no\":\"PLN-POS\\/2026\\/0001\",\"auto_created_from_slitting\":true,\"trigger\":\"paperroll_only_pln_prl_pos\",\"timer_accumulated_seconds\":3,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24 20:05:15\",\"timer_events\":[{\"type\":\"start\",\"at\":\"2026-07-24 20:05:15\"},{\"type\":\"end\",\"at\":\"2026-07-24 20:05:18\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 20:05:40\",\"timer_work_segments\":[{\"from\":\"2026-07-24 20:05:15\",\"to\":\"2026-07-24 20:05:18\",\"seconds\":3}],\"die_cutting_total_qty_pcs\":\"1800\",\"die_cutting_wastage_pcs\":\"0\",\"die_cutting_wastage_mtr\":\"0\",\"die_cutting_notes_text\":\"\",\"voice_language\":\"en-IN\",\"die_cutting_printed_roll_length_mtr\":\"2000.00\",\"die_cutting_photo_path\":\"\",\"die_cutting_voice_note_path\":\"\",\"die_cutting_submitted_at\":\"2026-07-24T18:05:39.569Z\"}",
    "duration_minutes": "0",
    "sequence_order": "5",
    "department": "pos",
    "previous_job_id": "10",
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "assigned_child_rolls": [
            {
                "roll_no": "SLC/2026/0231-A",
                "parent_roll_no": "SLC/2026/0231",
                "width_mm": 500,
                "length_mtr": 2000,
                "paper_type": "THERMAL PAPER",
                "company": "RAJ PAPER",
                "gsm": 184,
                "status": "Slitting",
                "job_no": "PLN-POS/2026/0001",
                "job_name": "POS-7950"
            },
            {
                "roll_no": "SLC/2026/0231-B",
                "parent_roll_no": "SLC/2026/0231",
                "width_mm": 500,
                "length_mtr": 2000,
                "paper_type": "THERMAL PAPER",
                "company": "RAJ PAPER",
                "gsm": 184,
                "status": "Slitting",
                "job_no": "PLN-POS/2026/0001",
                "job_name": "POS-7950"
            }
        ],
        "assigned_child_roll_count": 2,
        "assigned_parent_roll_no": "SLC/2026/0231",
        "assigned_last_batch_no": "BAT/2026/0003",
        "assigned_updated_at": "2026-07-24T19:05:38+02:00",
        "machine": "",
        "plan_no": "PLN-POS/2026/0001",
        "auto_created_from_slitting": true,
        "trigger": "paperroll_only_pln_prl_pos",
        "timer_accumulated_seconds": 3,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24 20:05:15",
        "timer_events": [
            {
                "type": "start",
                "at": "2026-07-24 20:05:15"
            },
            {
                "type": "end",
                "at": "2026-07-24 20:05:18"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 20:05:40",
        "timer_work_segments": [
            {
                "from": "2026-07-24 20:05:15",
                "to": "2026-07-24 20:05:18",
                "seconds": 3
            }
        ],
        "die_cutting_total_qty_pcs": "1800",
        "die_cutting_wastage_pcs": "0",
        "die_cutting_wastage_mtr": "0",
        "die_cutting_notes_text": "",
        "voice_language": "en-IN",
        "die_cutting_printed_roll_length_mtr": "2000.00",
        "die_cutting_photo_path": "",
        "die_cutting_voice_note_path": "",
        "die_cutting_submitted_at": "2026-07-24T18:05:39.569Z"
    }
}
```

### Job ID #14 (SLT/2026/0003 - jumbo_slitting)
```json
{
    "id": "14",
    "job_no": "SLT/2026/0003",
    "planning_id": "4",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0196",
    "job_type": "Slitting",
    "status": "Closed",
    "started_at": "2026-07-24 23:19:14",
    "completed_at": "2026-07-24 23:19:20",
    "operator_id": null,
    "notes": "Jumbo grouped slitting job | Plan: PLN-1PL/2026/0001 | JMB: SLT/2026/0003 | Job Name : 75mm - 1Ply",
    "created_at": "2026-07-24 22:36:18",
    "updated_at": "2026-07-24 23:19:20",
    "extra_data": "{\"plan_no\":\"PLN-1PL\\/2026\\/0001\",\"parent_roll\":\"SLC\\/2026\\/0196\",\"parent_details\":{\"roll_no\":\"SLC\\/2026\\/0196\",\"company\":\"SSE\",\"paper_type\":\"MAPLITHO WITHOUT GUM\",\"width_mm\":193,\"length_mtr\":5500,\"gsm\":80,\"weight_kg\":0,\"sqm\":1061.5,\"original_status\":\"Main\",\"remarks\":\"\"},\"child_rolls\":[{\"roll_no\":\"SLC\\/2026\\/0196-A\",\"parent_roll_no\":\"SLC\\/2026\\/0196\",\"width\":96.5,\"length\":5500,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":4,\"plan_no\":\"PLN-1PL\\/2026\\/0001\",\"allocation_id\":4,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, PaperRoll, Packaging, Dispatch\",\"job_no\":\"PLN-1PL\\/2026\\/0001\",\"job_name\":\"75mm - 1Ply\",\"company\":\"SSE\",\"paper_type\":\"MAPLITHO WITHOUT GUM\",\"gsm\":80,\"weight_kg\":0,\"sqm\":530.75,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"},{\"roll_no\":\"SLC\\/2026\\/0196-B\",\"parent_roll_no\":\"SLC\\/2026\\/0196\",\"width\":96.5,\"length\":5500,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":4,\"plan_no\":\"PLN-1PL\\/2026\\/0001\",\"allocation_id\":4,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, PaperRoll, Packaging, Dispatch\",\"job_no\":\"PLN-1PL\\/2026\\/0001\",\"job_name\":\"75mm - 1Ply\",\"company\":\"SSE\",\"paper_type\":\"MAPLITHO WITHOUT GUM\",\"gsm\":80,\"weight_kg\":0,\"sqm\":530.75,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"}],\"stock_rolls\":[],\"total_roll_count\":3,\"total_qty_mtr\":11000,\"material\":\"MAPLITHO WITHOUT GUM\",\"paper_combined\":false,\"batch_no\":\"BAT\\/2026\\/0004\",\"machine\":\"Jumbo Sliting\",\"operator_name\":\"System Admin\",\"flexo_request_accept_flow\":false,\"flexo_target_department\":\"jumbo slitting\",\"flexo_request_id\":0,\"flexo_job_id\":0,\"flexo_task_index\":-1,\"plan_allocations\":[{\"allocation_id\":4,\"planning_id\":4,\"plan_no\":\"PLN-1PL\\/2026\\/0001\",\"job_name\":\"75mm - 1Ply\",\"job_size\":\"35\",\"department_route\":\"Jumbo Slitting, PaperRoll, Packaging, Dispatch\",\"allocated_width_mm\":193,\"allocated_length_mtr\":11000,\"allocation_sequence\":1}],\"timer_accumulated_seconds\":1,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24T17:49:14.392Z\",\"timer_events\":[{\"type\":\"end\",\"at\":\"2026-07-24 19:49:15\"},{\"type\":\"end\",\"at\":\"2026-07-24 19:49:15\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 19:49:20\",\"timer_work_segments\":[{\"from\":\"2026-07-24 19:49:14\",\"to\":\"2026-07-24 19:49:15\",\"seconds\":1}],\"wastage_kg\":\"1\",\"operator_notes\":\"\"}",
    "duration_minutes": "0",
    "sequence_order": "1",
    "department": "jumbo_slitting",
    "previous_job_id": null,
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "plan_no": "PLN-1PL/2026/0001",
        "parent_roll": "SLC/2026/0196",
        "parent_details": {
            "roll_no": "SLC/2026/0196",
            "company": "SSE",
            "paper_type": "MAPLITHO WITHOUT GUM",
            "width_mm": 193,
            "length_mtr": 5500,
            "gsm": 80,
            "weight_kg": 0,
            "sqm": 1061.5,
            "original_status": "Main",
            "remarks": ""
        },
        "child_rolls": [
            {
                "roll_no": "SLC/2026/0196-A",
                "parent_roll_no": "SLC/2026/0196",
                "width": 96.5,
                "length": 5500,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 4,
                "plan_no": "PLN-1PL/2026/0001",
                "allocation_id": 4,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, PaperRoll, Packaging, Dispatch",
                "job_no": "PLN-1PL/2026/0001",
                "job_name": "75mm - 1Ply",
                "company": "SSE",
                "paper_type": "MAPLITHO WITHOUT GUM",
                "gsm": 80,
                "weight_kg": 0,
                "sqm": 530.75,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            },
            {
                "roll_no": "SLC/2026/0196-B",
                "parent_roll_no": "SLC/2026/0196",
                "width": 96.5,
                "length": 5500,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 4,
                "plan_no": "PLN-1PL/2026/0001",
                "allocation_id": 4,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, PaperRoll, Packaging, Dispatch",
                "job_no": "PLN-1PL/2026/0001",
                "job_name": "75mm - 1Ply",
                "company": "SSE",
                "paper_type": "MAPLITHO WITHOUT GUM",
                "gsm": 80,
                "weight_kg": 0,
                "sqm": 530.75,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            }
        ],
        "stock_rolls": [],
        "total_roll_count": 3,
        "total_qty_mtr": 11000,
        "material": "MAPLITHO WITHOUT GUM",
        "paper_combined": false,
        "batch_no": "BAT/2026/0004",
        "machine": "Jumbo Sliting",
        "operator_name": "System Admin",
        "flexo_request_accept_flow": false,
        "flexo_target_department": "jumbo slitting",
        "flexo_request_id": 0,
        "flexo_job_id": 0,
        "flexo_task_index": -1,
        "plan_allocations": [
            {
                "allocation_id": 4,
                "planning_id": 4,
                "plan_no": "PLN-1PL/2026/0001",
                "job_name": "75mm - 1Ply",
                "job_size": "35",
                "department_route": "Jumbo Slitting, PaperRoll, Packaging, Dispatch",
                "allocated_width_mm": 193,
                "allocated_length_mtr": 11000,
                "allocation_sequence": 1
            }
        ],
        "timer_accumulated_seconds": 1,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24T17:49:14.392Z",
        "timer_events": [
            {
                "type": "end",
                "at": "2026-07-24 19:49:15"
            },
            {
                "type": "end",
                "at": "2026-07-24 19:49:15"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 19:49:20",
        "timer_work_segments": [
            {
                "from": "2026-07-24 19:49:14",
                "to": "2026-07-24 19:49:15",
                "seconds": 1
            }
        ],
        "wastage_kg": "1",
        "operator_notes": ""
    }
}
```

### Job ID #15 (PRL-1PL/2026/0001 - paperroll)
```json
{
    "id": "15",
    "job_no": "PRL-1PL/2026/0001",
    "planning_id": "4",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0196",
    "job_type": "Finishing",
    "status": "Completed",
    "started_at": null,
    "completed_at": "2026-07-24 23:36:15",
    "operator_id": null,
    "notes": "PaperRoll job queued from upstream | Plan: PLN-1PL/2026/0001 | From: SLT/2026/0003 | PaperRoll: PRL-1PL/2026/0001 | Job name: 75mm - 1Ply",
    "created_at": "2026-07-24 22:36:18",
    "updated_at": "2026-07-24 23:36:15",
    "extra_data": "{\"assigned_child_rolls\":[{\"roll_no\":\"SLC/2026/0196-A\",\"parent_roll_no\":\"SLC/2026/0196\",\"width_mm\":96.5,\"length_mtr\":5500,\"paper_type\":\"MAPLITHO WITHOUT GUM\",\"company\":\"SSE\",\"gsm\":80,\"status\":\"Slitting\",\"job_no\":\"PLN-1PL/2026/0001\",\"job_name\":\"75mm - 1Ply\"},{\"roll_no\":\"SLC/2026/0196-B\",\"parent_roll_no\":\"SLC/2026/0196\",\"width_mm\":96.5,\"length_mtr\":5500,\"paper_type\":\"MAPLITHO WITHOUT GUM\",\"company\":\"SSE\",\"gsm\":80,\"status\":\"Slitting\",\"job_no\":\"PLN-1PL/2026/0001\",\"job_name\":\"75mm - 1Ply\"}],\"assigned_child_roll_count\":2,\"assigned_parent_roll_no\":\"SLC/2026/0196\",\"assigned_last_batch_no\":\"BAT/2026/0004\",\"assigned_updated_at\":\"2026-07-24T19:06:18+02:00\",\"machine\":\"\",\"plan_no\":\"PLN-1PL/2026/0001\",\"direct_paperroll_bypass\":false}",
    "duration_minutes": null,
    "sequence_order": "4",
    "department": "paperroll",
    "previous_job_id": "14",
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "assigned_child_rolls": [
            {
                "roll_no": "SLC/2026/0196-A",
                "parent_roll_no": "SLC/2026/0196",
                "width_mm": 96.5,
                "length_mtr": 5500,
                "paper_type": "MAPLITHO WITHOUT GUM",
                "company": "SSE",
                "gsm": 80,
                "status": "Slitting",
                "job_no": "PLN-1PL/2026/0001",
                "job_name": "75mm - 1Ply"
            },
            {
                "roll_no": "SLC/2026/0196-B",
                "parent_roll_no": "SLC/2026/0196",
                "width_mm": 96.5,
                "length_mtr": 5500,
                "paper_type": "MAPLITHO WITHOUT GUM",
                "company": "SSE",
                "gsm": 80,
                "status": "Slitting",
                "job_no": "PLN-1PL/2026/0001",
                "job_name": "75mm - 1Ply"
            }
        ],
        "assigned_child_roll_count": 2,
        "assigned_parent_roll_no": "SLC/2026/0196",
        "assigned_last_batch_no": "BAT/2026/0004",
        "assigned_updated_at": "2026-07-24T19:06:18+02:00",
        "machine": "",
        "plan_no": "PLN-1PL/2026/0001",
        "direct_paperroll_bypass": false
    }
}
```

### Job ID #16 (OPL-1PL/2026/0001 - oneply)
```json
{
    "id": "16",
    "job_no": "OPL-1PL/2026/0001",
    "planning_id": "4",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0196",
    "job_type": "Finishing",
    "status": "Completed",
    "started_at": "2026-07-24 23:36:01",
    "completed_at": "2026-07-24 23:36:15",
    "operator_id": null,
    "notes": "One Ply job queued from upstream | Plan: PLN-1PL/2026/0001 | From: SLT/2026/0003 | One Ply: OPL-1PL/2026/0001 | Job name: 75mm - 1Ply",
    "created_at": "2026-07-24 22:36:18",
    "updated_at": "2026-07-24 23:36:15",
    "extra_data": "{\"assigned_child_rolls\":[{\"roll_no\":\"SLC\\/2026\\/0196-A\",\"parent_roll_no\":\"SLC\\/2026\\/0196\",\"width_mm\":96.5,\"length_mtr\":5500,\"paper_type\":\"MAPLITHO WITHOUT GUM\",\"company\":\"SSE\",\"gsm\":80,\"status\":\"Slitting\",\"job_no\":\"PLN-1PL\\/2026\\/0001\",\"job_name\":\"75mm - 1Ply\"},{\"roll_no\":\"SLC\\/2026\\/0196-B\",\"parent_roll_no\":\"SLC\\/2026\\/0196\",\"width_mm\":96.5,\"length_mtr\":5500,\"paper_type\":\"MAPLITHO WITHOUT GUM\",\"company\":\"SSE\",\"gsm\":80,\"status\":\"Slitting\",\"job_no\":\"PLN-1PL\\/2026\\/0001\",\"job_name\":\"75mm - 1Ply\"}],\"assigned_child_roll_count\":2,\"assigned_parent_roll_no\":\"SLC\\/2026\\/0196\",\"assigned_last_batch_no\":\"BAT\\/2026\\/0004\",\"assigned_updated_at\":\"2026-07-24T19:06:18+02:00\",\"machine\":\"\",\"plan_no\":\"PLN-1PL\\/2026\\/0001\",\"auto_created_from_slitting\":true,\"trigger\":\"paperroll_only_pln_prl_oneply\",\"timer_accumulated_seconds\":2,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24 20:06:01\",\"timer_events\":[{\"type\":\"start\",\"at\":\"2026-07-24 20:06:01\"},{\"type\":\"end\",\"at\":\"2026-07-24 20:06:03\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 20:06:15\",\"timer_work_segments\":[{\"from\":\"2026-07-24 20:06:01\",\"to\":\"2026-07-24 20:06:03\",\"seconds\":2}],\"die_cutting_total_qty_pcs\":\"2500\",\"die_cutting_wastage_pcs\":\"0\",\"die_cutting_wastage_mtr\":\"0\",\"die_cutting_notes_text\":\"\",\"voice_language\":\"en-IN\",\"die_cutting_printed_roll_length_mtr\":\"5500.00\",\"die_cutting_photo_path\":\"\",\"die_cutting_voice_note_path\":\"\",\"die_cutting_submitted_at\":\"2026-07-24T18:06:14.024Z\"}",
    "duration_minutes": "0",
    "sequence_order": "5",
    "department": "oneply",
    "previous_job_id": "14",
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "assigned_child_rolls": [
            {
                "roll_no": "SLC/2026/0196-A",
                "parent_roll_no": "SLC/2026/0196",
                "width_mm": 96.5,
                "length_mtr": 5500,
                "paper_type": "MAPLITHO WITHOUT GUM",
                "company": "SSE",
                "gsm": 80,
                "status": "Slitting",
                "job_no": "PLN-1PL/2026/0001",
                "job_name": "75mm - 1Ply"
            },
            {
                "roll_no": "SLC/2026/0196-B",
                "parent_roll_no": "SLC/2026/0196",
                "width_mm": 96.5,
                "length_mtr": 5500,
                "paper_type": "MAPLITHO WITHOUT GUM",
                "company": "SSE",
                "gsm": 80,
                "status": "Slitting",
                "job_no": "PLN-1PL/2026/0001",
                "job_name": "75mm - 1Ply"
            }
        ],
        "assigned_child_roll_count": 2,
        "assigned_parent_roll_no": "SLC/2026/0196",
        "assigned_last_batch_no": "BAT/2026/0004",
        "assigned_updated_at": "2026-07-24T19:06:18+02:00",
        "machine": "",
        "plan_no": "PLN-1PL/2026/0001",
        "auto_created_from_slitting": true,
        "trigger": "paperroll_only_pln_prl_oneply",
        "timer_accumulated_seconds": 2,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24 20:06:01",
        "timer_events": [
            {
                "type": "start",
                "at": "2026-07-24 20:06:01"
            },
            {
                "type": "end",
                "at": "2026-07-24 20:06:03"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 20:06:15",
        "timer_work_segments": [
            {
                "from": "2026-07-24 20:06:01",
                "to": "2026-07-24 20:06:03",
                "seconds": 2
            }
        ],
        "die_cutting_total_qty_pcs": "2500",
        "die_cutting_wastage_pcs": "0",
        "die_cutting_wastage_mtr": "0",
        "die_cutting_notes_text": "",
        "voice_language": "en-IN",
        "die_cutting_printed_roll_length_mtr": "5500.00",
        "die_cutting_photo_path": "",
        "die_cutting_voice_note_path": "",
        "die_cutting_submitted_at": "2026-07-24T18:06:14.024Z"
    }
}
```

### Job ID #18 (SLT/2026/0004 - jumbo_slitting)
```json
{
    "id": "18",
    "job_no": "SLT/2026/0004",
    "planning_id": "5",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0226",
    "job_type": "Slitting",
    "status": "Closed",
    "started_at": "2026-07-24 23:19:41",
    "completed_at": "2026-07-24 23:19:53",
    "operator_id": null,
    "notes": "Jumbo grouped slitting job | Plan: PLN-2PL/2026/0001 | JMB: SLT/2026/0004 | Job Name : 210mm-2Ply",
    "created_at": "2026-07-24 22:36:48",
    "updated_at": "2026-07-24 23:19:53",
    "extra_data": "{\"plan_no\":\"PLN-2PL\\/2026\\/0001\",\"parent_roll\":\"SLC\\/2026\\/0228\",\"parent_details\":{\"roll_no\":\"SLC\\/2026\\/0228\",\"company\":\"ANANT AGENCY\",\"paper_type\":\"CARBONLESS PAPER CF-WHITE\",\"width_mm\":1000,\"length_mtr\":1000,\"gsm\":140,\"weight_kg\":0,\"sqm\":1000,\"original_status\":\"Main\",\"remarks\":\"\"},\"child_rolls\":[{\"roll_no\":\"SLC\\/2026\\/0226-A\",\"parent_roll_no\":\"SLC\\/2026\\/0226\",\"width\":500,\"length\":1000,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":5,\"plan_no\":\"PLN-2PL\\/2026\\/0001\",\"allocation_id\":5,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, PaperRoll, Packaging, Dispatch\",\"job_no\":\"PLN-2PL\\/2026\\/0001\",\"job_name\":\"210mm-2Ply\",\"company\":\"ANANT AGENCY\",\"paper_type\":\"CARBONLESS PAPER CF-YELLOW\",\"gsm\":140,\"weight_kg\":0,\"sqm\":500,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"},{\"roll_no\":\"SLC\\/2026\\/0226-B\",\"parent_roll_no\":\"SLC\\/2026\\/0226\",\"width\":500,\"length\":1000,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":5,\"plan_no\":\"PLN-2PL\\/2026\\/0001\",\"allocation_id\":5,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, PaperRoll, Packaging, Dispatch\",\"job_no\":\"PLN-2PL\\/2026\\/0001\",\"job_name\":\"210mm-2Ply\",\"company\":\"ANANT AGENCY\",\"paper_type\":\"CARBONLESS PAPER CF-YELLOW\",\"gsm\":140,\"weight_kg\":0,\"sqm\":500,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"},{\"roll_no\":\"SLC\\/2026\\/0228-A\",\"parent_roll_no\":\"SLC\\/2026\\/0228\",\"width\":500,\"length\":1000,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":5,\"plan_no\":\"PLN-2PL\\/2026\\/0001\",\"allocation_id\":6,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, PaperRoll, Packaging, Dispatch\",\"job_no\":\"PLN-2PL\\/2026\\/0001\",\"job_name\":\"210mm-2Ply\",\"company\":\"ANANT AGENCY\",\"paper_type\":\"CARBONLESS PAPER CF-WHITE\",\"gsm\":140,\"weight_kg\":0,\"sqm\":500,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"},{\"roll_no\":\"SLC\\/2026\\/0228-B\",\"parent_roll_no\":\"SLC\\/2026\\/0228\",\"width\":500,\"length\":1000,\"mode\":\"WIDTH\",\"dest\":\"JOB\",\"status\":\"Slitting\",\"planning_id\":5,\"plan_no\":\"PLN-2PL\\/2026\\/0001\",\"allocation_id\":6,\"allocation_sequence\":1,\"department_route\":\"Jumbo Slitting, PaperRoll, Packaging, Dispatch\",\"job_no\":\"PLN-2PL\\/2026\\/0001\",\"job_name\":\"210mm-2Ply\",\"company\":\"ANANT AGENCY\",\"paper_type\":\"CARBONLESS PAPER CF-WHITE\",\"gsm\":140,\"weight_kg\":0,\"sqm\":500,\"wastage\":0,\"remarks\":\"\",\"remarks_live\":\"\",\"status_live\":\"Slitting\"}],\"stock_rolls\":[],\"total_roll_count\":5,\"total_qty_mtr\":2000,\"material\":\"CARBONLESS PAPER CF-WHITE\",\"paper_combined\":false,\"batch_no\":\"BAT\\/2026\\/0006\",\"machine\":\"Jumbo Sliting\",\"operator_name\":\"System Admin\",\"flexo_request_accept_flow\":false,\"flexo_target_department\":\"jumbo slitting\",\"flexo_request_id\":0,\"flexo_job_id\":0,\"flexo_task_index\":-1,\"plan_allocations\":[{\"allocation_id\":6,\"planning_id\":5,\"plan_no\":\"PLN-2PL\\/2026\\/0001\",\"job_name\":\"210mm-2Ply\",\"job_size\":\"36\",\"department_route\":\"Jumbo Slitting, PaperRoll, Packaging, Dispatch\",\"allocated_width_mm\":1000,\"allocated_length_mtr\":2000,\"allocation_sequence\":1}],\"timer_accumulated_seconds\":3,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24T17:49:41.672Z\",\"timer_events\":[{\"type\":\"end\",\"at\":\"2026-07-24 19:49:44\"},{\"type\":\"end\",\"at\":\"2026-07-24 19:49:44\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 19:49:53\",\"timer_work_segments\":[{\"from\":\"2026-07-24 19:49:41\",\"to\":\"2026-07-24 19:49:44\",\"seconds\":3}],\"wastage_kg\":\"1\",\"operator_notes\":\"\"}",
    "duration_minutes": "0",
    "sequence_order": "1",
    "department": "jumbo_slitting",
    "previous_job_id": null,
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "plan_no": "PLN-2PL/2026/0001",
        "parent_roll": "SLC/2026/0228",
        "parent_details": {
            "roll_no": "SLC/2026/0228",
            "company": "ANANT AGENCY",
            "paper_type": "CARBONLESS PAPER CF-WHITE",
            "width_mm": 1000,
            "length_mtr": 1000,
            "gsm": 140,
            "weight_kg": 0,
            "sqm": 1000,
            "original_status": "Main",
            "remarks": ""
        },
        "child_rolls": [
            {
                "roll_no": "SLC/2026/0226-A",
                "parent_roll_no": "SLC/2026/0226",
                "width": 500,
                "length": 1000,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 5,
                "plan_no": "PLN-2PL/2026/0001",
                "allocation_id": 5,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, PaperRoll, Packaging, Dispatch",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply",
                "company": "ANANT AGENCY",
                "paper_type": "CARBONLESS PAPER CF-YELLOW",
                "gsm": 140,
                "weight_kg": 0,
                "sqm": 500,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            },
            {
                "roll_no": "SLC/2026/0226-B",
                "parent_roll_no": "SLC/2026/0226",
                "width": 500,
                "length": 1000,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 5,
                "plan_no": "PLN-2PL/2026/0001",
                "allocation_id": 5,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, PaperRoll, Packaging, Dispatch",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply",
                "company": "ANANT AGENCY",
                "paper_type": "CARBONLESS PAPER CF-YELLOW",
                "gsm": 140,
                "weight_kg": 0,
                "sqm": 500,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            },
            {
                "roll_no": "SLC/2026/0228-A",
                "parent_roll_no": "SLC/2026/0228",
                "width": 500,
                "length": 1000,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 5,
                "plan_no": "PLN-2PL/2026/0001",
                "allocation_id": 6,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, PaperRoll, Packaging, Dispatch",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply",
                "company": "ANANT AGENCY",
                "paper_type": "CARBONLESS PAPER CF-WHITE",
                "gsm": 140,
                "weight_kg": 0,
                "sqm": 500,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            },
            {
                "roll_no": "SLC/2026/0228-B",
                "parent_roll_no": "SLC/2026/0228",
                "width": 500,
                "length": 1000,
                "mode": "WIDTH",
                "dest": "JOB",
                "status": "Slitting",
                "planning_id": 5,
                "plan_no": "PLN-2PL/2026/0001",
                "allocation_id": 6,
                "allocation_sequence": 1,
                "department_route": "Jumbo Slitting, PaperRoll, Packaging, Dispatch",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply",
                "company": "ANANT AGENCY",
                "paper_type": "CARBONLESS PAPER CF-WHITE",
                "gsm": 140,
                "weight_kg": 0,
                "sqm": 500,
                "wastage": 0,
                "remarks": "",
                "remarks_live": "",
                "status_live": "Slitting"
            }
        ],
        "stock_rolls": [],
        "total_roll_count": 5,
        "total_qty_mtr": 2000,
        "material": "CARBONLESS PAPER CF-WHITE",
        "paper_combined": false,
        "batch_no": "BAT/2026/0006",
        "machine": "Jumbo Sliting",
        "operator_name": "System Admin",
        "flexo_request_accept_flow": false,
        "flexo_target_department": "jumbo slitting",
        "flexo_request_id": 0,
        "flexo_job_id": 0,
        "flexo_task_index": -1,
        "plan_allocations": [
            {
                "allocation_id": 6,
                "planning_id": 5,
                "plan_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply",
                "job_size": "36",
                "department_route": "Jumbo Slitting, PaperRoll, Packaging, Dispatch",
                "allocated_width_mm": 1000,
                "allocated_length_mtr": 2000,
                "allocation_sequence": 1
            }
        ],
        "timer_accumulated_seconds": 3,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24T17:49:41.672Z",
        "timer_events": [
            {
                "type": "end",
                "at": "2026-07-24 19:49:44"
            },
            {
                "type": "end",
                "at": "2026-07-24 19:49:44"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 19:49:53",
        "timer_work_segments": [
            {
                "from": "2026-07-24 19:49:41",
                "to": "2026-07-24 19:49:44",
                "seconds": 3
            }
        ],
        "wastage_kg": "1",
        "operator_notes": ""
    }
}
```

### Job ID #19 (PRL-2PL/2026/0001 - paperroll)
```json
{
    "id": "19",
    "job_no": "PRL-2PL/2026/0001",
    "planning_id": "5",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0226",
    "job_type": "Finishing",
    "status": "Completed",
    "started_at": null,
    "completed_at": "2026-07-24 23:36:55",
    "operator_id": null,
    "notes": "PaperRoll job queued from upstream | Plan: PLN-2PL/2026/0001 | From: SLT/2026/0004 | PaperRoll: PRL-2PL/2026/0001 | Job name: 210mm-2Ply",
    "created_at": "2026-07-24 22:36:48",
    "updated_at": "2026-07-24 23:36:55",
    "extra_data": "{\"assigned_child_rolls\":[{\"roll_no\":\"SLC/2026/0226-A\",\"parent_roll_no\":\"SLC/2026/0226\",\"width_mm\":500,\"length_mtr\":1000,\"paper_type\":\"CARBONLESS PAPER CF-YELLOW\",\"company\":\"ANANT AGENCY\",\"gsm\":140,\"status\":\"Slitting\",\"job_no\":\"PLN-2PL/2026/0001\",\"job_name\":\"210mm-2Ply\"},{\"roll_no\":\"SLC/2026/0226-B\",\"parent_roll_no\":\"SLC/2026/0226\",\"width_mm\":500,\"length_mtr\":1000,\"paper_type\":\"CARBONLESS PAPER CF-YELLOW\",\"company\":\"ANANT AGENCY\",\"gsm\":140,\"status\":\"Slitting\",\"job_no\":\"PLN-2PL/2026/0001\",\"job_name\":\"210mm-2Ply\"},{\"roll_no\":\"SLC/2026/0228-A\",\"parent_roll_no\":\"SLC/2026/0228\",\"width_mm\":500,\"length_mtr\":1000,\"paper_type\":\"CARBONLESS PAPER CF-WHITE\",\"company\":\"ANANT AGENCY\",\"gsm\":140,\"status\":\"Slitting\",\"job_no\":\"PLN-2PL/2026/0001\",\"job_name\":\"210mm-2Ply\"},{\"roll_no\":\"SLC/2026/0228-B\",\"parent_roll_no\":\"SLC/2026/0228\",\"width_mm\":500,\"length_mtr\":1000,\"paper_type\":\"CARBONLESS PAPER CF-WHITE\",\"company\":\"ANANT AGENCY\",\"gsm\":140,\"status\":\"Slitting\",\"job_no\":\"PLN-2PL/2026/0001\",\"job_name\":\"210mm-2Ply\"}],\"assigned_child_roll_count\":4,\"assigned_parent_roll_no\":\"SLC/2026/0228\",\"assigned_last_batch_no\":\"BAT/2026/0006\",\"assigned_updated_at\":\"2026-07-24T19:06:48+02:00\",\"machine\":\"\",\"plan_no\":\"PLN-2PL/2026/0001\",\"direct_paperroll_bypass\":false}",
    "duration_minutes": null,
    "sequence_order": "4",
    "department": "paperroll",
    "previous_job_id": "18",
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "assigned_child_rolls": [
            {
                "roll_no": "SLC/2026/0226-A",
                "parent_roll_no": "SLC/2026/0226",
                "width_mm": 500,
                "length_mtr": 1000,
                "paper_type": "CARBONLESS PAPER CF-YELLOW",
                "company": "ANANT AGENCY",
                "gsm": 140,
                "status": "Slitting",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply"
            },
            {
                "roll_no": "SLC/2026/0226-B",
                "parent_roll_no": "SLC/2026/0226",
                "width_mm": 500,
                "length_mtr": 1000,
                "paper_type": "CARBONLESS PAPER CF-YELLOW",
                "company": "ANANT AGENCY",
                "gsm": 140,
                "status": "Slitting",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply"
            },
            {
                "roll_no": "SLC/2026/0228-A",
                "parent_roll_no": "SLC/2026/0228",
                "width_mm": 500,
                "length_mtr": 1000,
                "paper_type": "CARBONLESS PAPER CF-WHITE",
                "company": "ANANT AGENCY",
                "gsm": 140,
                "status": "Slitting",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply"
            },
            {
                "roll_no": "SLC/2026/0228-B",
                "parent_roll_no": "SLC/2026/0228",
                "width_mm": 500,
                "length_mtr": 1000,
                "paper_type": "CARBONLESS PAPER CF-WHITE",
                "company": "ANANT AGENCY",
                "gsm": 140,
                "status": "Slitting",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply"
            }
        ],
        "assigned_child_roll_count": 4,
        "assigned_parent_roll_no": "SLC/2026/0228",
        "assigned_last_batch_no": "BAT/2026/0006",
        "assigned_updated_at": "2026-07-24T19:06:48+02:00",
        "machine": "",
        "plan_no": "PLN-2PL/2026/0001",
        "direct_paperroll_bypass": false
    }
}
```

### Job ID #20 (TPL-2PL/2026/0001 - twoply)
```json
{
    "id": "20",
    "job_no": "TPL-2PL/2026/0001",
    "planning_id": "5",
    "sales_order_id": null,
    "roll_no": "SLC/2026/0226",
    "job_type": "Finishing",
    "status": "Completed",
    "started_at": "2026-07-24 23:36:42",
    "completed_at": "2026-07-24 23:36:55",
    "operator_id": null,
    "notes": "Two Ply job queued from upstream | Plan: PLN-2PL/2026/0001 | From: SLT/2026/0004 | Two Ply: TPL-2PL/2026/0001 | Job name: 210mm-2Ply",
    "created_at": "2026-07-24 22:36:48",
    "updated_at": "2026-07-24 23:36:55",
    "extra_data": "{\"assigned_child_rolls\":[{\"roll_no\":\"SLC\\/2026\\/0226-A\",\"parent_roll_no\":\"SLC\\/2026\\/0226\",\"width_mm\":500,\"length_mtr\":1000,\"paper_type\":\"CARBONLESS PAPER CF-YELLOW\",\"company\":\"ANANT AGENCY\",\"gsm\":140,\"status\":\"Slitting\",\"job_no\":\"PLN-2PL\\/2026\\/0001\",\"job_name\":\"210mm-2Ply\"},{\"roll_no\":\"SLC\\/2026\\/0226-B\",\"parent_roll_no\":\"SLC\\/2026\\/0226\",\"width_mm\":500,\"length_mtr\":1000,\"paper_type\":\"CARBONLESS PAPER CF-YELLOW\",\"company\":\"ANANT AGENCY\",\"gsm\":140,\"status\":\"Slitting\",\"job_no\":\"PLN-2PL\\/2026\\/0001\",\"job_name\":\"210mm-2Ply\"},{\"roll_no\":\"SLC\\/2026\\/0228-A\",\"parent_roll_no\":\"SLC\\/2026\\/0228\",\"width_mm\":500,\"length_mtr\":1000,\"paper_type\":\"CARBONLESS PAPER CF-WHITE\",\"company\":\"ANANT AGENCY\",\"gsm\":140,\"status\":\"Slitting\",\"job_no\":\"PLN-2PL\\/2026\\/0001\",\"job_name\":\"210mm-2Ply\"},{\"roll_no\":\"SLC\\/2026\\/0228-B\",\"parent_roll_no\":\"SLC\\/2026\\/0228\",\"width_mm\":500,\"length_mtr\":1000,\"paper_type\":\"CARBONLESS PAPER CF-WHITE\",\"company\":\"ANANT AGENCY\",\"gsm\":140,\"status\":\"Slitting\",\"job_no\":\"PLN-2PL\\/2026\\/0001\",\"job_name\":\"210mm-2Ply\"}],\"assigned_child_roll_count\":4,\"assigned_parent_roll_no\":\"SLC\\/2026\\/0228\",\"assigned_last_batch_no\":\"BAT\\/2026\\/0006\",\"assigned_updated_at\":\"2026-07-24T19:06:48+02:00\",\"machine\":\"\",\"plan_no\":\"PLN-2PL\\/2026\\/0001\",\"auto_created_from_slitting\":true,\"trigger\":\"paperroll_only_pln_prl_twoply\",\"timer_accumulated_seconds\":2,\"timer_active\":false,\"timer_state\":\"completed\",\"timer_started_at\":\"2026-07-24 20:06:42\",\"timer_events\":[{\"type\":\"start\",\"at\":\"2026-07-24 20:06:42\"},{\"type\":\"end\",\"at\":\"2026-07-24 20:06:44\"}],\"timer_last_resumed_at\":\"\",\"timer_paused_at\":\"\",\"timer_pause_started_at\":\"\",\"timer_ended_at\":\"2026-07-24 20:06:55\",\"timer_work_segments\":[{\"from\":\"2026-07-24 20:06:42\",\"to\":\"2026-07-24 20:06:44\",\"seconds\":2}],\"die_cutting_total_qty_pcs\":\"3500\",\"die_cutting_wastage_pcs\":\"0\",\"die_cutting_wastage_mtr\":\"0\",\"die_cutting_notes_text\":\"\",\"voice_language\":\"en-IN\",\"die_cutting_printed_roll_length_mtr\":\"1000.00\",\"die_cutting_photo_path\":\"\",\"die_cutting_voice_note_path\":\"\",\"die_cutting_submitted_at\":\"2026-07-24T18:06:54.008Z\"}",
    "duration_minutes": "0",
    "sequence_order": "5",
    "department": "twoply",
    "previous_job_id": "18",
    "run_group_id": null,
    "deleted_at": null,
    "extra_data_parsed": {
        "assigned_child_rolls": [
            {
                "roll_no": "SLC/2026/0226-A",
                "parent_roll_no": "SLC/2026/0226",
                "width_mm": 500,
                "length_mtr": 1000,
                "paper_type": "CARBONLESS PAPER CF-YELLOW",
                "company": "ANANT AGENCY",
                "gsm": 140,
                "status": "Slitting",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply"
            },
            {
                "roll_no": "SLC/2026/0226-B",
                "parent_roll_no": "SLC/2026/0226",
                "width_mm": 500,
                "length_mtr": 1000,
                "paper_type": "CARBONLESS PAPER CF-YELLOW",
                "company": "ANANT AGENCY",
                "gsm": 140,
                "status": "Slitting",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply"
            },
            {
                "roll_no": "SLC/2026/0228-A",
                "parent_roll_no": "SLC/2026/0228",
                "width_mm": 500,
                "length_mtr": 1000,
                "paper_type": "CARBONLESS PAPER CF-WHITE",
                "company": "ANANT AGENCY",
                "gsm": 140,
                "status": "Slitting",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply"
            },
            {
                "roll_no": "SLC/2026/0228-B",
                "parent_roll_no": "SLC/2026/0228",
                "width_mm": 500,
                "length_mtr": 1000,
                "paper_type": "CARBONLESS PAPER CF-WHITE",
                "company": "ANANT AGENCY",
                "gsm": 140,
                "status": "Slitting",
                "job_no": "PLN-2PL/2026/0001",
                "job_name": "210mm-2Ply"
            }
        ],
        "assigned_child_roll_count": 4,
        "assigned_parent_roll_no": "SLC/2026/0228",
        "assigned_last_batch_no": "BAT/2026/0006",
        "assigned_updated_at": "2026-07-24T19:06:48+02:00",
        "machine": "",
        "plan_no": "PLN-2PL/2026/0001",
        "auto_created_from_slitting": true,
        "trigger": "paperroll_only_pln_prl_twoply",
        "timer_accumulated_seconds": 2,
        "timer_active": false,
        "timer_state": "completed",
        "timer_started_at": "2026-07-24 20:06:42",
        "timer_events": [
            {
                "type": "start",
                "at": "2026-07-24 20:06:42"
            },
            {
                "type": "end",
                "at": "2026-07-24 20:06:44"
            }
        ],
        "timer_last_resumed_at": "",
        "timer_paused_at": "",
        "timer_pause_started_at": "",
        "timer_ended_at": "2026-07-24 20:06:55",
        "timer_work_segments": [
            {
                "from": "2026-07-24 20:06:42",
                "to": "2026-07-24 20:06:44",
                "seconds": 2
            }
        ],
        "die_cutting_total_qty_pcs": "3500",
        "die_cutting_wastage_pcs": "0",
        "die_cutting_wastage_mtr": "0",
        "die_cutting_notes_text": "",
        "voice_language": "en-IN",
        "die_cutting_printed_roll_length_mtr": "1000.00",
        "die_cutting_photo_path": "",
        "die_cutting_voice_note_path": "",
        "die_cutting_submitted_at": "2026-07-24T18:06:54.008Z"
    }
}
```

## 3. Full Packing Operator Entries Table
_No entries found in `packing_operator_entries` table._

## 4. Full Finished Goods Stock Table
_No records found in `finished_goods_stock` table._

## 5. Full Finished Goods Dispatch Log Table
_No dispatch log records found._


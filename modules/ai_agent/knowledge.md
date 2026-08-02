# AI Agent Slash Commands Knowledge Base

Here is the list of custom priority modes (slash commands) created for your ERP AI Agent, and the types of queries you can make with them.

## 1. Dispatch & Packing Mode (`/dispatch`, `/packing`)
Use this mode when you want to search strictly for dispatch details, delivery status, packing slips, or ready queues.

**Valid Keywords you can use in this mode:**
- `dispatch`, `packing`, `ready`, `slip`, `delivery`, `challan`, `sales person`, `ডিসপ্যাচ`, `রেডি`

**Example Queries:**
- `/dispatch show me today's ready slips`
- `/dispatch packing status for invoice 123`
- `/packing show delivery details for Krishna`

## 2. Job & Planning Mode (`/job`, `/jobcard`, `/planning`)
Use this mode when you want to isolate your search strictly to the Live Production Floor, Jobs, and Planning Board.

**Valid Keywords you can use in this mode:**
- `job`, `planning`, `floor`, `production`, `card`, `brc`, `jmb`, `pck`, `work`, `status`

**Example Queries:**
- `/job show floor production status`
- `/planning what jobs are assigned to operator XYZ?`
- `/jobcard show brc details for job 105`

## 3. Paper Stock Mode (`/paperstock`)
Use this mode to strictly search the paper roll stock, slitting details, and jumbo rolls.

**Example Queries:**
- `/paperstock show thermal rolls below 500 meters`
- `/paperstock stock summary`

## 4. Plate Mode (`/plate`)
Use this mode to strictly search the plate and cylinder database.

**Example Queries:**
- `/plate show me chromo paper details` (Will show error because it's plate mode)
- `/plate show cylinder status for job 230`

## Settings Keyword Notice
If you type `/setting` or use words like `view settings`, `system config`, the AI will directly give you a quick link to open the AI Agent Settings Panel.

---
**Note:** If you use a strict mode (like `/dispatch`) but ask about plates or paper, the AI will strictly reject it and ask you to use `/erp` instead!

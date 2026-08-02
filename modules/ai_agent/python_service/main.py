from fastapi import FastAPI, Request, Form
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
import requests
import urllib.parse

app = FastAPI(title="ERP AI Agent Gateway")

# Allow CORS for local PHP testing
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

PHP_API_URL = "http://localhost/calipot-erp/shree-label-php/modules/ai_agent/api.php"

@app.api_route("/api/query", methods=["GET", "POST"])
async def query_gateway(request: Request):
    # Parse request data (form data or query params)
    data = {}
    if request.method == "POST":
        form_data = await request.form()
        data = dict(form_data)
    else:
        data = dict(request.query_params)
        
    action = data.get("action", "")
    prompt = data.get("prompt", "")
    
    # --- PROXY FALLBACK LOGIC ---
    # For Phase 1, we pass EVERYTHING through to api.php to ensure 100% backward compatibility
    # while we route the UI to the Python gateway.
    
    try:
        # Pass cookies along for session management
        cookies = request.cookies
        
        response = requests.post(PHP_API_URL, data=data, cookies=cookies)
        
        if response.status_code == 200:
            try:
                return response.json()
            except ValueError:
                # If api.php didn't return JSON, wrap it
                return {"ok": False, "error": "PHP API returned non-JSON response", "raw": response.text}
        else:
            return {"ok": False, "error": f"PHP API returned status {response.status_code}", "raw": response.text}
            
    except Exception as e:
        return JSONResponse(status_code=500, content={"ok": False, "error": str(e)})

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8000)

<!-- =========================================
     LEAFLET MAP + LIST + EDIT MODAL (FULL FIXED)
========================================= -->
<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

<style>

.wrapper{
    display:grid;
    grid-template-columns:350px 1fr;
    gap:15px;
    margin-top:20px;
    font-family:Arial;
}

/* LIST */
.list-card{
    background:#fff;
    padding:15px;
    border-radius:12px;
    box-shadow:0 2px 8px rgba(0,0,0,0.1);
    height:700px;
    overflow:auto;
}

.item{
    border:1px solid #eee;
    padding:10px;
    border-radius:10px;
    margin-bottom:10px;
}

.item h4{
    margin:0;
}

.item small{
    display:block;
    color:#777;
    margin-top:5px;
}

.btn{
    margin-top:8px;
    padding:6px 10px;
    border:none;
    border-radius:6px;
    cursor:pointer;
    font-size:12px;
}

.edit{
    background:#3498db;
    color:white;
}

/* MAP */
.map-card{
    background:#fff;
    padding:15px;
    border-radius:12px;
    box-shadow:0 2px 8px rgba(0,0,0,0.1);
}

#maintenanceMap{
    width:100%;
    height:700px;
    border-radius:12px;
}

/* MODAL */
#editModal{
    display:none;
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.5);
    justify-content:center;
    align-items:center;
    z-index:9999;
}

.modal-box{
    background:#fff;
    width:400px;
    padding:20px;
    border-radius:12px;
}

.modal-box input,
.modal-box textarea{
    width:100%;
    padding:8px;
    margin-top:5px;
    margin-bottom:10px;
}

.modal-box button{
    width:100%;
    padding:10px;
    border:none;
    border-radius:8px;
    cursor:pointer;
}

.save{
    background:#27ae60;
    color:white;
}

.close{
    background:#e74c3c;
    color:white;
    margin-top:8px;
}

</style>

<div class="wrapper">

    <!-- LIST -->
    <div class="list-card">
        <h3>📋 Maintenance List</h3>
        <div id="maintenanceList"></div>
    </div>

    <!-- MAP -->
    <div class="map-card">
        <h3>🗺️ Map View</h3>
        <div id="maintenanceMap"></div>
    </div>

</div>

<!-- =========================================
     EDIT MODAL
========================================= -->
<div id="editModal">

    <div class="modal-box">

        <h3>Edit Maintenance</h3>

        <input type="hidden" id="edit_id">

        <label>Date</label>
        <input type="date" id="edit_date">

        <label>Start</label>
        <input type="time" id="edit_start">

        <label>End</label>
        <input type="time" id="edit_end">

        <label>Description</label>
        <textarea id="edit_desc"></textarea>

        <label>Radius</label>
        <input type="number" id="edit_radius">

        <button class="save" onclick="submitUpdate()">
            Save Update
        </button>

        <button class="close" onclick="closeModal()">
            Close
        </button>

    </div>

</div>

<script>

/* =========================================
   MAP INIT
========================================= */
let map;
let layers = [];

const barangayData = {
    "Bonuan Gueset": { lat:16.0585, lng:120.3345 },
    "Bonuan Boquig": { lat:16.0600, lng:120.3200 },
    "Bonuan Binloc": { lat:16.0620, lng:120.3100 },
    "Lucao": { lat:16.0435, lng:120.3310 },
    "Tapuac": { lat:16.0460, lng:120.3450 }
};

function initMap(){
    map = L.map('maintenanceMap').setView([16.0430, 120.3335], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
        attribution:'© OpenStreetMap'
    }).addTo(map);
}

/* =========================================
   CLEAR MAP
========================================= */
function clearMap(){
    layers.forEach(l => map.removeLayer(l));
    layers = [];
}

/* =========================================
   LOAD DATA
========================================= */
async function loadData(){

    try{

        const res = await fetch(
            "http://localhost/crowdsourcedapi/api/maintenance/get.php",
            { credentials:"include" }
        );

        const result = await res.json();

        if(!result.success) throw new Error(result.message);

        const list = document.getElementById("maintenanceList");
        list.innerHTML = "";

        clearMap();

        const items = result.data || [];

        items.forEach(item => {

            /* ================= LIST ================= */
            const div = document.createElement("div");
            div.className = "item";

            div.innerHTML = `
                <h4>⚡ ${item.company_name}</h4>
                <small>
                    📅 ${item.maintenance_date}<br>
                    🕒 ${item.start_time} - ${item.end_time}<br>
                    📍 ${item.affected_barangays?.join(", ") || ""}
                </small>

                <button class="btn edit">Edit</button>
            `;

            div.querySelector(".edit").onclick = () => openEdit(item);

            list.appendChild(div);

            /* ================= MAP ================= */
            const barangays = item.affected_barangays || [];

            let latSum = 0;
            let lngSum = 0;
            let count = 0;

            barangays.forEach(name => {

                const geo = barangayData[name];
                if(!geo) return;

                latSum += geo.lat;
                lngSum += geo.lng;
                count++;

                const marker = L.marker([geo.lat, geo.lng]).addTo(map);

                marker.bindPopup(`
                    <b>${name}</b><br>
                    ${item.company_name}
                `);

                layers.push(marker);
            });

            if(count > 0){

                const circle = L.circle(
                    [latSum/count, lngSum/count],
                    {
                        radius: item.radius || 2000,
                        color:"#e74c3c",
                        fillOpacity:0.2
                    }
                ).addTo(map);

                layers.push(circle);
            }

        });

    }catch(err){
        console.error(err);
    }
}

/* =========================================
   OPEN MODAL
========================================= */
function openEdit(item){

    console.log("EDIT DATA:", item);

    document.getElementById("edit_id").value = item.id;
    document.getElementById("edit_date").value = item.maintenance_date;
    document.getElementById("edit_start").value = item.start_time;
    document.getElementById("edit_end").value = item.end_time;
    document.getElementById("edit_desc").value = item.description;
    document.getElementById("edit_radius").value = item.radius;

    document.getElementById("editModal").style.display = "flex";
}

/* =========================================
   CLOSE MODAL
========================================= */
function closeModal(){
    document.getElementById("editModal").style.display = "none";
}

/* =========================================
   UPDATE API
========================================= */
async function submitUpdate(){

    const payload = {
        maintenance_id: document.getElementById("edit_id").value,
        maintenance_date: document.getElementById("edit_date").value,
        start_time: document.getElementById("edit_start").value,
        end_time: document.getElementById("edit_end").value,
        description: document.getElementById("edit_desc").value,
        radius: Number(document.getElementById("edit_radius").value)
    };

    try{

        const res = await fetch(
            "http://localhost/crowdsourcedapi/api/maintenance/update.php",
            {
                method:"POST",
                headers:{ "Content-Type":"application/json" },
                credentials:"include",
                body: JSON.stringify(payload)
            }
        );

        const result = await res.json();

        if(!result.success){
            throw new Error(result.message);
        }

        alert("Updated successfully!");

        closeModal();
        loadData();

    }catch(err){
        alert("Error: " + err.message);
    }
}

/* INIT */
initMap();
loadData();
setInterval(loadData, 30000);

</script>
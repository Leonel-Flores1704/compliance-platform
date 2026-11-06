# Content of the fictional test documents for Manufacturas Alfa Demo, S.A. de C.V.
# Each document: meta + pages (PDF) or sections (DOCX/TXT).
# Page item: ("h1"|"h2"|"p"|"li", text)

ORG = "Manufacturas Alfa Demo, S.A. de C.V."

DOCS = []

def add(**kw):
    DOCS.append(kw)

# ---------------------------------------------------------------- text PDFs
add(id="D01", file="licencia_ambiental_unica_2026.pdf", kind="pdf", lang="es",
    title="Licencia Ambiental Única", category="Permit/License", access="all_staff",
    version="1.0", expiration="2027-03-31",
    pages=[
     [("h1","Licencia Ambiental Única (LAU)"),
      ("p","Titular: Manufacturas Alfa Demo, S.A. de C.V."),
      ("p","Domicilio de la planta: Av. Industrial Ficticia 1200, Parque Industrial Demo, Apodaca, Nuevo León."),
      ("p","Número de licencia: LAU-NL-2026-0417-DEMO."),
      ("p","Fecha de emisión: 1 de abril de 2026. Vigencia: hasta el 31 de marzo de 2027."),
      ("p","Autoridad emisora: autoridad ambiental estatal (dato ficticio para pruebas)."),
      ("p","Responsable ambiental designado por la empresa: Ing. Laura Méndez Garza, Gerente de Seguridad, Salud y Medio Ambiente."),
      ("p","Giro autorizado: fabricación de piezas metálicas troqueladas, almacenamiento y distribución."),
     ],
     [("h1","Condicionantes de operación"),
      ("li","1. Presentar el reporte anual de emisiones a más tardar el 30 de abril de cada año."),
      ("li","2. El nivel de ruido en el perímetro de la planta no debe superar 68 dB(A) en horario diurno (07:00 a 22:00) ni 65 dB(A) en horario nocturno."),
      ("li","3. Los residuos peligrosos (aceites gastados, trapos impregnados y lodos de pintura) no pueden permanecer en el almacén temporal más de 90 días naturales."),
      ("li","4. Mantener una bitácora de generación de residuos peligrosos actualizada cada mes."),
      ("li","5. Realizar un monitoreo perimetral de ruido cada 6 meses y conservar los resultados durante 5 años."),
      ("p","El incumplimiento de cualquiera de estas condicionantes puede dar lugar a la suspensión de la licencia."),
     ],
     [("h1","Renovación y modificaciones"),
      ("p","La solicitud de renovación debe presentarse al menos 60 días naturales antes del vencimiento, es decir, a más tardar el 30 de enero de 2027."),
      ("p","Cualquier ampliación de la planta o cambio de proceso que aumente las emisiones debe notificarse a la autoridad antes de ejecutarse."),
      ("p","Copia de esta licencia debe estar disponible en la caseta de vigilancia para cualquier visita de inspección."),
     ]])

add(id="D02", file="politica_riesgos_psicosociales_v1.pdf", kind="pdf", lang="es",
    title="Política de Prevención de Riesgos Psicosociales", category="Policy", access="all_staff",
    version="1.0", expiration=None, note="Versión anterior (2024). Subir primero como versión 1.0 del documento; luego D03 la reemplaza como 2.0.",
    pages=[
     [("h1","Política de Prevención de Riesgos Psicosociales"),
      ("p","Versión 1.0. Fecha de aprobación: 15 de febrero de 2024. Aprobada por: Dirección General."),
      ("p","Esta política establece el compromiso de Manufacturas Alfa Demo para prevenir los factores de riesgo psicosocial y promover un entorno organizacional favorable, en atención a la NOM-035-STPS-2018."),
      ("p","Aplica a todo el personal de planta, oficinas y almacén."),
     ],
     [("h1","Identificación y evaluación"),
      ("p","El cuestionario de identificación de factores de riesgo psicosocial se aplicará cada 2 años a todo el personal."),
      ("p","El Comité de Entorno Organizacional está integrado por 3 personas: un representante de Recursos Humanos, un supervisor de producción y un representante de los trabajadores."),
     ],
     [("h1","Quejas y denuncias"),
      ("p","Las quejas por violencia laboral o prácticas contrarias al entorno favorable se reciben en el correo buzon.entorno@alfademo.example."),
      ("p","Toda queja será atendida en un plazo máximo de 10 días hábiles."),
     ]])

add(id="D03", file="politica_riesgos_psicosociales_v2.pdf", kind="pdf", lang="es",
    title="Política de Prevención de Riesgos Psicosociales", category="Policy", access="all_staff",
    version="2.0", expiration=None, note="Versión vigente. Subir como versión 2.0 del mismo documento que D02.",
    pages=[
     [("h1","Política de Prevención de Riesgos Psicosociales"),
      ("p","Versión 2.0. Fecha de aprobación: 1 de junio de 2026. Sustituye a la versión 1.0 del 15 de febrero de 2024."),
      ("p","Esta política establece el compromiso de Manufacturas Alfa Demo para prevenir los factores de riesgo psicosocial y promover un entorno organizacional favorable, en atención a la NOM-035-STPS-2018."),
      ("p","Aplica a todo el personal de planta, oficinas y almacén, incluidos practicantes y personal de nuevo ingreso."),
     ],
     [("h1","Identificación y evaluación"),
      ("p","El cuestionario de identificación de factores de riesgo psicosocial se aplicará cada año, durante el mes de octubre, a todo el personal."),
      ("p","El Comité de Entorno Organizacional está integrado por 5 personas: dos representantes de Recursos Humanos, un supervisor de producción, un representante de almacén y un representante de los trabajadores."),
      ("p","Los resultados se presentan a la Dirección General a más tardar el 15 de diciembre de cada año."),
     ],
     [("h1","Quejas y denuncias"),
      ("p","Las quejas se reciben en el correo buzon.entorno@alfademo.example o en la línea anónima 81-0000-0035, disponible de lunes a viernes de 08:00 a 18:00."),
      ("p","Toda queja será atendida en un plazo máximo de 5 días hábiles."),
     ]])

add(id="D04", file="information_security_policy.pdf", kind="pdf", lang="en",
    title="Information Security Policy", category="Policy", access="compliance_only",
    version="3.1", expiration=None,
    pages=[
     [("h1","Information Security Policy"),
      ("p","Version 3.1. Approved on January 20, 2026 by the General Manager. Owner: Carlos Ruiz Treviño, IT Manager."),
      ("p","Scope: all employees, contractors and systems of Manufacturas Alfa Demo, including the ERP, email, file servers and production control computers."),
     ],
     [("h1","Access control"),
      ("li","Passwords must have at least 12 characters and include uppercase letters, lowercase letters and numbers."),
      ("li","Passwords must be changed every 180 days."),
      ("li","Multi-factor authentication (MFA) is mandatory for remote access and for administrator accounts."),
      ("li","User accounts are locked after 5 consecutive failed sign-in attempts."),
      ("li","Access rights are reviewed every 6 months by each department head."),
     ],
     [("h1","Incident reporting"),
      ("p","Any suspected security incident must be reported within 24 hours to security@alfademo.example or to IT extension 2040."),
      ("p","Lost or stolen laptops and phones must be reported immediately, and no later than 2 hours after the loss is noticed."),
     ],
     [("h1","Review and sanctions"),
      ("p","This policy is reviewed every year by the IT Manager. Violations may lead to disciplinary action according to the internal work regulations."),
     ]])

add(id="D05", file="programa_interno_proteccion_civil_2026.pdf", kind="pdf", lang="es",
    title="Programa Interno de Protección Civil 2026", category="Other", access="all_staff",
    version="1.0", expiration="2026-12-31",
    pages=[
     [("h1","Programa Interno de Protección Civil 2026"),
      ("p","Vigencia: del 1 de enero al 31 de diciembre de 2026. Coordinador del programa: Ing. Laura Méndez Garza."),
      ("p","El programa define la organización, las brigadas y los simulacros para responder a emergencias en la planta de Apodaca."),
     ],
     [("h1","Brigadas y simulacros"),
      ("p","La empresa cuenta con 4 brigadas: evacuación, primeros auxilios, combate de incendios, y búsqueda y rescate. En total hay 24 brigadistas capacitados."),
      ("p","Se realizarán 2 simulacros al año: uno en marzo (sismo) y otro en septiembre (incendio)."),
      ("p","El tiempo objetivo de evacuación total de la planta es de 4 minutos."),
     ],
     [("h1","Puntos de reunión"),
      ("li","Punto A: estacionamiento norte, junto a la caseta de vigilancia."),
      ("li","Punto B: área verde frente a oficinas administrativas."),
      ("li","Punto C: patio de maniobras del almacén, lado oriente."),
     ]])

add(id="D06", file="supplier_code_of_conduct.pdf", kind="pdf", lang="en",
    title="Supplier Code of Conduct", category="Policy", access="all_staff",
    version="1.2", expiration=None, note="Conflict test with D14 (gift limit).",
    pages=[
     [("h1","Supplier Code of Conduct"),
      ("p","Version 1.2, effective March 1, 2026. Applies to every supplier and contractor of Manufacturas Alfa Demo."),
      ("p","Suppliers must comply with applicable labor, environmental, health and safety laws."),
     ],
     [("h1","Gifts and conflicts of interest"),
      ("p","Employees may accept gifts from suppliers only if their value does not exceed USD 50 (approximately MXN 1,000) per supplier per year."),
      ("p","Suppliers must disclose in writing any family or financial relationship with an employee of Manufacturas Alfa Demo."),
     ],
     [("h1","Supplier audits"),
      ("p","Critical suppliers are audited every 18 months. A supplier with a critical finding has 30 days to submit a corrective action plan."),
     ]])

add(id="D07", file="aviso_privacidad_integral.pdf", kind="pdf", lang="es",
    title="Aviso de Privacidad Integral", category="Policy", access="all_staff",
    version="2.0", expiration=None,
    pages=[
     [("h1","Aviso de Privacidad Integral"),
      ("p","Responsable: Manufacturas Alfa Demo, S.A. de C.V. Versión 2.0, actualizada el 10 de julio de 2026."),
      ("p","Los datos personales de empleados, candidatos y proveedores se utilizan para la administración de la relación laboral o comercial, el pago de nómina y el cumplimiento de obligaciones legales."),
     ],
     [("h1","Conservación y derechos ARCO"),
      ("p","Los datos personales se conservan durante 5 años contados a partir de la terminación de la relación laboral o comercial."),
      ("p","Las solicitudes de acceso, rectificación, cancelación u oposición (derechos ARCO) se envían a privacidad@alfademo.example y se responden en un plazo máximo de 20 días hábiles."),
     ]])

add(id="D08", file="internal_audit_report_2025.pdf", kind="pdf", lang="en",
    title="Internal Compliance Audit Report 2025", category="Report", access="compliance_only",
    version="1.0", expiration=None,
    pages=[
     [("h1","Internal Compliance Audit Report 2025"),
      ("p","Audit period: January 1 to December 31, 2025. Report date: January 28, 2026. Lead auditor: Mariana Salinas Ortiz."),
      ("p","Summary: 7 findings were identified: 2 high, 3 medium and 2 low."),
     ],
     [("h1","High findings"),
      ("li","H-1: Hazardous waste was stored for 104 days in the temporary storage area, exceeding the 90-day limit of the environmental license."),
      ("li","H-2: 6 fire extinguishers in the warehouse had overdue inspections."),
      ("p","Corrective actions for high findings must be closed by March 31, 2026."),
     ],
     [("h1","Medium and low findings"),
      ("li","M-1: The hazardous waste log was not updated for two months."),
      ("li","M-2: 3 brigade members had expired first-aid training."),
      ("li","M-3: Supplier conflict-of-interest declarations were missing for 4 suppliers."),
      ("li","L-1: Evacuation route signs were faded in the office area."),
      ("li","L-2: The privacy notice on the website was an old version."),
     ],
     [("h1","Conclusion"),
      ("p","Overall compliance level: 86%. The next internal audit is scheduled for November 2026."),
     ]])

# ---------------------------------------------------------------- scanned PDFs
add(id="D09", file="dictamen_instalaciones_electricas_escaneado.pdf", kind="scan", lang="es",
    title="Dictamen de Verificación de Instalaciones Eléctricas", category="Certificate", access="all_staff",
    version="1.0", expiration="2028-05-15",
    pages=[
     [("h1","DICTAMEN DE VERIFICACIÓN"),
      ("h2","Instalaciones eléctricas"),
      ("p","Empresa: Manufacturas Alfa Demo, S.A. de C.V."),
      ("p","Número de dictamen: DVE-2026-0515-DEMO"),
      ("p","Unidad de verificación: UV-DEMO-123 (ficticia)"),
      ("p","Fecha de verificación: 15 de mayo de 2026"),
      ("p","Resultado: CUMPLE"),
      ("p","Vigencia: hasta el 15 de mayo de 2028"),
      ("p","Carga instalada verificada: 450 kW"),
     ]])

add(id="D10", file="constancia_uso_suelo_escaneada.pdf", kind="scan", lang="es",
    title="Constancia de Uso de Suelo", category="Permit/License", access="all_staff",
    version="1.0", expiration="2026-11-30", note="Vence pronto: sirve para probar alertas de 30 días (CMP-47).",
    pages=[
     [("h1","CONSTANCIA DE USO DE SUELO"),
      ("p","Municipio de Apodaca, Nuevo León (documento ficticio)"),
      ("p","Folio: US-APO-2025-8812-DEMO"),
      ("p","Predio: Av. Industrial Ficticia 1200"),
      ("p","Uso permitido: INDUSTRIA LIGERA"),
      ("p","Superficie: 12,500 m2"),
      ("p","Vigente hasta: 30 de noviembre de 2026"),
     ]])

add(id="D11", file="fire_extinguisher_inspection_scanned.pdf", kind="scan", lang="en",
    title="Fire Extinguisher Inspection Certificate", category="Certificate", access="all_staff",
    version="1.0", expiration="2026-12-15",
    pages=[
     [("h1","FIRE EXTINGUISHER INSPECTION CERTIFICATE"),
      ("p","Customer: Manufacturas Alfa Demo"),
      ("p","Certificate No.: FE-2025-1215-DEMO"),
      ("p","Inspection date: December 15, 2025"),
      ("p","Extinguishers inspected: 42"),
      ("p","Recharged: 6   Replaced: 2"),
     ],
     [("h1","NEXT INSPECTION"),
      ("p","Next inspection due: December 15, 2026"),
      ("p","Inspector: J. Morales (fictional)"),
      ("p","All units tagged and approved."),
     ]])

# ---------------------------------------------------------------- DOCX
add(id="D12", file="procedimiento_atencion_emergencias.docx", kind="docx", lang="es",
    title="Procedimiento de Atención a Emergencias", category="Policy", access="all_staff",
    version="1.0", expiration=None,
    sections=[
     ("Objetivo", ["Establecer las acciones que debe seguir el personal ante incendios, sismos y lesiones dentro de la planta."]),
     ("Responsabilidades", ["El jefe de brigada de evacuación coordina la salida del personal.", "Cada supervisor confirma por radio que su área quedó vacía."]),
     ("En caso de incendio", ["Activar la alarma más cercana y llamar a la extensión interna 5555.", "Usar el extintor solo si el fuego es pequeño y se cuenta con capacitación.", "Evacuar por la ruta señalizada hacia el punto de reunión asignado."]),
     ("En caso de sismo", ["Alejarse de ventanas y estantes altos.", "No usar el elevador del almacén.", "Esperar la indicación del brigadista antes de salir."]),
     ("Primeros auxilios", ["Los botiquines están en la entrada de cada nave y en recepción.", "Toda lesión, por menor que sea, se registra en la bitácora de incidentes el mismo día."]),
    ])

add(id="D13", file="data_retention_disposal_procedure.docx", kind="docx", lang="en",
    title="Data Retention and Disposal Procedure", category="Policy", access="compliance_only",
    version="1.0", expiration=None,
    sections=[
     ("Purpose", ["Define how long company records are kept and how they are destroyed."]),
     ("Retention periods", ["Personnel files: 5 years after the employee leaves.", "Accounting and tax records: 10 years.", "Security camera recordings: 30 days.", "Visitor logs: 1 year."]),
     ("Disposal methods", ["Paper records are destroyed with cross-cut shredders (security level P-4).", "Hard drives are physically destroyed by an approved supplier, which issues a destruction certificate."]),
     ("Approvals", ["The Compliance Manager approves every disposal batch and keeps the disposal log for 5 years."]),
    ])

add(id="D14", file="reglamento_interior_trabajo_extracto.docx", kind="docx", lang="es",
    title="Reglamento Interior de Trabajo (extracto)", category="Regulation", access="all_staff",
    version="4.0", expiration=None, note="Prueba de conflicto con D06 (límite de regalos).",
    sections=[
     ("Horario de trabajo", ["El horario del turno administrativo es de 07:00 a 16:00, de lunes a viernes.", "El turno de producción tiene una tolerancia de entrada de 10 minutos."]),
     ("Equipo de protección personal", ["Es obligatorio usar casco, lentes de seguridad, guantes y calzado de seguridad en todas las áreas de producción."]),
     ("Regalos de proveedores", ["El personal no puede aceptar regalos de proveedores con un valor mayor a MXN 500."]),
     ("Sanciones", ["Las faltas al reglamento se sancionan con amonestación escrita, y en caso de reincidencia con suspensión de 1 a 8 días."]),
    ])

add(id="D15", file="lockout_tagout_procedure.docx", kind="docx", lang="en",
    title="Lockout/Tagout Procedure", category="Policy", access="all_staff",
    version="2.0", expiration=None,
    sections=[
     ("Scope", ["Applies to maintenance work on presses, conveyors and compressors."]),
     ("Steps", ["1. Notify affected employees.", "2. Shut down the equipment.", "3. Isolate all energy sources.", "4. Apply a personal lock and tag.", "5. Release stored energy.", "6. Verify zero energy by trying to start the equipment."]),
     ("Authorized employees", ["Only employees who passed the annual lockout/tagout training may apply locks.", "Each authorized employee receives a red lock with their name."]),
     ("Verification", ["The maintenance supervisor audits 2 lockout/tagout jobs every month."]),
    ])

# ---------------------------------------------------------------- TXT
add(id="D16", file="minuta_comite_seguridad_2026_09.txt", kind="txt", lang="es", encoding="cp1252",
    title="Minuta del Comité de Seguridad e Higiene, septiembre 2026", category="Report", access="all_staff",
    version="1.0", expiration=None, note="Codificada en Windows-1252 para probar detección de codificación (CMP-25).",
    text="""MINUTA DEL COMITÉ DE SEGURIDAD E HIGIENE
=========================================
Fecha: 18 de septiembre de 2026
Lugar: Sala de juntas, planta Apodaca

ASISTENTES
----------
Laura Méndez Garza (coordinadora), Jorge Peña (producción), Ana Lucía Vela (almacén), Rubén Ochoa (representante de trabajadores).

ACUERDOS
--------
1. Comprar 10 extintores nuevos de polvo químico seco antes del 31 de octubre de 2026.
2. Repintar las líneas de las rutas de evacuación de la nave 2.
3. El simulacro de incendio se realizará el 25 de septiembre de 2026 a las 10:00.

SEGUIMIENTO
-----------
El último simulacro registró un tiempo de evacuación de 3 minutos 40 segundos.
Próxima reunión: 16 de octubre de 2026.
""")

add(id="D17", file="training_log_summary_2026.txt", kind="txt", lang="en", encoding="utf-8",
    title="Training Log Summary 2026", category="Report", access="all_staff",
    version="1.0", expiration=None,
    text="""TRAINING LOG SUMMARY 2026
=========================
Prepared by: Human Resources
Last update: September 30, 2026

LOCKOUT/TAGOUT
--------------
Date: February 10, 2026. Participants: 18 maintenance employees. Instructor: external (fictional).

PSYCHOSOCIAL RISK AWARENESS (NOM-035)
-------------------------------------
Dates: April and May 2026. Participants: 96 employees.

FIRST AID
---------
Date: June 3, 2026. Participants: 12 brigade members. Certificate valid for 2 years.

PENDING
-------
Forklift operation refresher for 7 warehouse operators, planned for November 2026.
""")

add(id="D18", file="directorio_emergencias.txt", kind="txt", lang="es", encoding="utf-8",
    title="Directorio de emergencias", category="Other", access="all_staff",
    version="1.0", expiration=None,
    text="""DIRECTORIO DE EMERGENCIAS
=========================
Planta Apodaca - Manufacturas Alfa Demo (todos los números son ficticios)

INTERNOS
--------
Emergencias internas: extensión 5555
Vigilancia: extensión 1001
Seguridad, Salud y Medio Ambiente: extensión 3030

EXTERNOS
--------
Bomberos (ficticio): 81-0000-1111
Cruz Roja (ficticio): 81-0000-2222
Protección Civil municipal (ficticio): 81-0000-3333
Hospital más cercano: Hospital Demo Norte, a 4 km de la planta.
""")

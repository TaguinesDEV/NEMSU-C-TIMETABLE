import os
import mysql.connector
import json

conf = {
    'host': os.getenv('ACADEMIC_SCHEDULING_DB_HOST', 'localhost'),
    'user': os.getenv('ACADEMIC_SCHEDULING_DB_USER', 'root'),
    'password': os.getenv('ACADEMIC_SCHEDULING_DB_PASS', ''),
    'database': os.getenv('ACADEMIC_SCHEDULING_DB_NAME', 'academic_scheduling'),
}
conn = mysql.connector.connect(**conf)
cur = conn.cursor(dictionary=True)
cur.execute('SELECT id, status, progress_percent, current_generation, total_generations, best_fitness, error_message, input_data FROM schedule_jobs WHERE id = 25')
row = cur.fetchone()
print(row)
cur.close()
conn.close()

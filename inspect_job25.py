import mysql.connector
import json

conf = {'host': 'localhost', 'user': 'root', 'password': '', 'database': 'academic_scheduling'}
conn = mysql.connector.connect(**conf)
cur = conn.cursor(dictionary=True)
cur.execute('SELECT id, status, progress_percent, current_generation, total_generations, best_fitness, error_message, input_data FROM schedule_jobs WHERE id = 25')
row = cur.fetchone()
print(row)
cur.close()
conn.close()